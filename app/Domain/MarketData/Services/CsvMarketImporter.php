<?php

namespace App\Domain\MarketData\Services;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use App\Models\Broker;
use App\Models\BrokerSummaryDaily;
use App\Models\CorporateAction;
use App\Models\DataImportRun;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\MarketHoliday;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class CsvMarketImporter
{
    public const DATASETS = [
        'prices_daily',
        'market_holidays',
        'financial_statements',
        'corporate_actions',
        'broker_summaries_daily',
    ];

    public function __construct(
        private readonly RefreshLatestQuotes $refreshQuotes,
    ) {}

    public function importUploadedFile(
        UploadedFile|string $file,
        string $dataset,
        DataSource $source,
        ?int $userId = null,
    ): DataImportRun {
        if (! in_array($dataset, self::DATASETS, true)) {
            throw new InvalidArgumentException("Unsupported CSV dataset: {$dataset}");
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $startedAt = now('UTC');

        $run = DataImportRun::query()->create([
            'data_source_id' => $source->id,
            'user_id' => $userId,
            'dataset' => $dataset,
            'mode' => 'csv',
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        if (! $path || ! is_readable($path)) {
            $run->forceFill([
                'status' => 'failed',
                'error_summary' => 'Uploaded CSV file could not be read.',
                'finished_at' => now('UTC'),
            ])->save();

            return $run;
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            $run->forceFill([
                'status' => 'failed',
                'error_summary' => 'Failed to open CSV stream.',
                'finished_at' => now('UTC'),
            ])->save();

            return $run;
        }

        $rawHeaders = fgetcsv($handle);
        if (! is_array($rawHeaders) || empty($rawHeaders)) {
            fclose($handle);
            $run->forceFill([
                'status' => 'failed',
                'error_summary' => 'CSV header row is missing or empty.',
                'finished_at' => now('UTC'),
            ])->save();

            return $run;
        }

        $headers = array_map(function ($h): string {
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return strtolower(trim((string) $clean));
        }, $rawHeaders);

        $exchanges = Exchange::query()->get()->keyBy(fn (Exchange $e) => strtoupper($e->code));
        $instruments = Instrument::query()->with('exchange:id,code')->get()->keyBy(
            fn (Instrument $i) => strtoupper($i->exchange->code).':'.strtoupper($i->symbol)
        );
        $brokersByMemberCode = Broker::query()
            ->whereNotNull('exchange_member_code')
            ->get()
            ->keyBy(fn (Broker $b) => strtoupper((string) $b->exchange_member_code));

        $processed = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $batch = [];
        $now = now('UTC');
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $processed++;
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), null);
            }
            $assoc = array_combine($headers, array_slice($row, 0, count($headers)));
            if (! is_array($assoc)) {
                $skipped++;
                $this->pushError($errors, "Line {$line}: malformed column count.");

                continue;
            }

            try {
                $record = match ($dataset) {
                    'prices_daily' => $this->mapPriceRow($assoc, $instruments, $source, $now),
                    'market_holidays' => $this->mapHolidayRow($assoc, $exchanges, $now),
                    'financial_statements' => $this->mapStatementRow($assoc, $instruments, $source, $now),
                    'corporate_actions' => $this->mapCorporateActionRow($assoc, $instruments, $source, $now),
                    'broker_summaries_daily' => $this->mapBrokerSummaryRow($assoc, $instruments, $brokersByMemberCode, $source, $now),
                };

                $batch[] = $record;
                if (count($batch) >= 250) {
                    $inserted += $this->flushBatch($dataset, $batch);
                    $batch = [];
                }
            } catch (\Throwable $e) {
                $skipped++;
                $this->pushError($errors, "Line {$line}: {$e->getMessage()}");
            }
        }

        fclose($handle);

        if (! empty($batch)) {
            $inserted += $this->flushBatch($dataset, $batch);
        }

        if ($dataset === 'prices_daily' && $inserted > 0) {
            $this->refreshQuotes->execute();
        }

        $status = ($inserted === 0 && $skipped > 0) ? 'failed' : 'completed';

        if ($inserted > 0) {
            $source->forceFill(['last_success_at' => now('UTC')])->save();
        } elseif ($status === 'failed') {
            $source->forceFill(['last_failure_at' => now('UTC')])->save();
        }

        $run->forceFill([
            'status' => $status,
            'rows_processed' => $processed,
            'rows_inserted' => $inserted,
            'rows_skipped' => $skipped,
            'error_summary' => empty($errors) ? null : implode("\n", $errors),
            'finished_at' => now('UTC'),
        ])->save();

        return $run->fresh();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Instrument>  $instruments
     * @return array<string, mixed>
     */
    private function mapPriceRow(array $row, $instruments, DataSource $source, $now): array
    {
        $inst = $this->resolveInstrument($row, $instruments);
        $tradeDate = $this->parseDate($row['trade_date'] ?? null, 'trade_date');

        $open = $this->parsePositiveDecimal($row['open'] ?? null, 'open', 6);
        $high = $this->parsePositiveDecimal($row['high'] ?? null, 'high', 6);
        $low = $this->parsePositiveDecimal($row['low'] ?? null, 'low', 6);
        $close = $this->parsePositiveDecimal($row['close'] ?? null, 'close', 6);
        $adjClose = $this->parseOptionalDecimal($row['adj_close'] ?? null, 6) ?? $close;

        $maxOC = Dec::max($open, $close);
        $minOC = Dec::min($open, $close);
        if (Dec::cmp($high, $maxOC) < 0 || Dec::cmp($low, $minOC) > 0 || Dec::cmp($high, $low) < 0) {
            throw new InvalidArgumentException('OHLC invariant failed (high < max(open,close) or low > min(open,close)).');
        }

        $volRaw = trim((string) ($row['volume'] ?? ''));
        if ($volRaw === '' || ! is_numeric($volRaw) || (int) $volRaw < 0) {
            throw new InvalidArgumentException('Invalid volume.');
        }
        $volume = (int) $volRaw;

        $valueTraded = $this->parseOptionalDecimal($row['value_traded'] ?? null, 4)
            ?? Dec::mul($close, (string) $volume, 4);

        return [
            'instrument_id' => $inst->id,
            'trade_date' => $tradeDate,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'adj_close' => $adjClose,
            'volume' => $volume,
            'value_traded' => $valueTraded,
            'data_source_id' => $source->id,
            'is_demo' => (bool) $source->is_demo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Exchange>  $exchanges
     * @return array<string, mixed>
     */
    private function mapHolidayRow(array $row, $exchanges, $now): array
    {
        $code = strtoupper(trim((string) ($row['exchange_code'] ?? '')));
        $exchange = $exchanges->get($code);
        if (! $exchange) {
            throw new InvalidArgumentException("Unknown exchange_code '{$code}'.");
        }

        $date = $this->parseDate($row['date'] ?? null, 'date');
        $name = CsvSanitizer::cleanText($row['name'] ?? null);
        if (! $name) {
            throw new InvalidArgumentException('Holiday name is required.');
        }

        $halfRaw = strtolower(trim((string) ($row['is_half_day'] ?? '0')));
        $isHalf = in_array($halfRaw, ['1', 'true', 'yes'], true);

        return [
            'exchange_id' => $exchange->id,
            'date' => $date,
            'name' => mb_substr($name, 0, 150),
            'is_half_day' => $isHalf,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Instrument>  $instruments
     * @return array<string, mixed>
     */
    private function mapStatementRow(array $row, $instruments, DataSource $source, $now): array
    {
        $inst = $this->resolveInstrument($row, $instruments);
        $periodType = strtoupper(trim((string) ($row['period_type'] ?? '')));
        if (! in_array($periodType, ['FY', 'Q1', 'Q2', 'Q3', 'Q4', 'TTM'], true)) {
            throw new InvalidArgumentException("Invalid period_type '{$periodType}'.");
        }

        $fiscalYear = (int) ($row['fiscal_year'] ?? 0);
        if ($fiscalYear < 1990 || $fiscalYear > 2100) {
            throw new InvalidArgumentException('Invalid fiscal_year.');
        }

        $periodEndDate = $this->parseDate($row['period_end_date'] ?? null, 'period_end_date');
        $publishedAt = $this->parseDate($row['published_at'] ?? null, 'published_at');
        $currency = strtoupper(trim((string) ($row['currency'] ?? $inst->currency)));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Invalid currency.');
        }

        $finRaw = strtolower(trim((string) ($row['is_financial_sector'] ?? '')));
        $isFin = $finRaw !== ''
            ? in_array($finRaw, ['1', 'true', 'yes'], true)
            : ($inst->sector === 'Financials');

        return [
            'instrument_id' => $inst->id,
            'period_type' => $periodType,
            'fiscal_year' => $fiscalYear,
            'period_end_date' => $periodEndDate,
            'published_at' => $publishedAt,
            'currency' => $currency,
            'is_financial_sector' => $isFin,
            'revenue' => $this->parseOptionalDecimal($row['revenue'] ?? null, 4),
            'gross_profit' => $this->parseOptionalDecimal($row['gross_profit'] ?? null, 4),
            'operating_income' => $this->parseOptionalDecimal($row['operating_income'] ?? null, 4),
            'net_income' => $this->parseOptionalDecimal($row['net_income'] ?? null, 4),
            'ebitda' => $this->parseOptionalDecimal($row['ebitda'] ?? null, 4),
            'extraordinary_items' => $this->parseOptionalDecimal($row['extraordinary_items'] ?? null, 4),
            'total_assets' => $this->parseOptionalDecimal($row['total_assets'] ?? null, 4),
            'current_assets' => $this->parseOptionalDecimal($row['current_assets'] ?? null, 4),
            'cash_and_equivalents' => $this->parseOptionalDecimal($row['cash_and_equivalents'] ?? null, 4),
            'total_liabilities' => $this->parseOptionalDecimal($row['total_liabilities'] ?? null, 4),
            'current_liabilities' => $this->parseOptionalDecimal($row['current_liabilities'] ?? null, 4),
            'total_debt' => $this->parseOptionalDecimal($row['total_debt'] ?? null, 4),
            'total_equity' => $this->parseOptionalDecimal($row['total_equity'] ?? null, 4),
            'operating_cash_flow' => $this->parseOptionalDecimal($row['operating_cash_flow'] ?? null, 4),
            'capex' => $this->parseOptionalDecimal($row['capex'] ?? null, 4),
            'free_cash_flow' => $this->parseOptionalDecimal($row['free_cash_flow'] ?? null, 4),
            'dividends_paid' => $this->parseOptionalDecimal($row['dividends_paid'] ?? null, 4),
            'shares_outstanding' => $this->parseOptionalDecimal($row['shares_outstanding'] ?? null, 4),
            'data_source_id' => $source->id,
            'is_demo' => (bool) $source->is_demo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Instrument>  $instruments
     * @return array<string, mixed>
     */
    private function mapCorporateActionRow(array $row, $instruments, DataSource $source, $now): array
    {
        $inst = $this->resolveInstrument($row, $instruments);
        $type = strtolower(trim((string) ($row['action_type'] ?? '')));
        if (! in_array($type, ['cash_dividend', 'stock_split', 'reverse_split', 'bonus_issue', 'rights_issue'], true)) {
            throw new InvalidArgumentException("Invalid action_type '{$type}'.");
        }

        $exDate = $this->parseDate($row['ex_date'] ?? null, 'ex_date');
        $recordDate = isset($row['record_date']) && trim((string) $row['record_date']) !== ''
            ? $this->parseDate($row['record_date'], 'record_date')
            : null;
        $paymentDate = isset($row['payment_date']) && trim((string) $row['payment_date']) !== ''
            ? $this->parseDate($row['payment_date'], 'payment_date')
            : null;

        $currency = isset($row['declared_currency']) && trim((string) $row['declared_currency']) !== ''
            ? strtoupper(trim((string) $row['declared_currency']))
            : $inst->currency;

        return [
            'instrument_id' => $inst->id,
            'action_type' => $type,
            'ex_date' => $exDate,
            'record_date' => $recordDate,
            'payment_date' => $paymentDate,
            'declared_currency' => $currency,
            'cash_amount' => $this->parseOptionalDecimal($row['cash_amount'] ?? null, 6),
            'ratio_numerator' => $this->parseOptionalDecimal($row['ratio_numerator'] ?? null, 6),
            'ratio_denominator' => $this->parseOptionalDecimal($row['ratio_denominator'] ?? null, 6),
            'notes' => CsvSanitizer::cleanText($row['notes'] ?? null),
            'data_source_id' => $source->id,
            'is_demo' => (bool) $source->is_demo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Instrument>  $instruments
     * @param  \Illuminate\Support\Collection<string, Broker>  $brokersByMemberCode
     * @return array<string, mixed>
     */
    private function mapBrokerSummaryRow(array $row, $instruments, $brokersByMemberCode, DataSource $source, $now): array
    {
        $inst = $this->resolveInstrument($row, $instruments);
        $tradeDate = $this->parseDate($row['trade_date'] ?? null, 'trade_date');

        $brokerCode = strtoupper(trim((string) ($row['broker_code'] ?? '')));
        if ($brokerCode === '' || preg_match('/^[A-Z0-9_-]{1,16}$/', $brokerCode) !== 1) {
            throw new InvalidArgumentException("Invalid broker_code '{$brokerCode}'.");
        }

        $buyVolRaw = trim((string) ($row['buy_volume'] ?? '0'));
        $sellVolRaw = trim((string) ($row['sell_volume'] ?? '0'));
        if ($buyVolRaw === '' || ! is_numeric($buyVolRaw) || (int) $buyVolRaw < 0 || $sellVolRaw === '' || ! is_numeric($sellVolRaw) || (int) $sellVolRaw < 0) {
            throw new InvalidArgumentException('buy_volume and sell_volume must be non-negative integers.');
        }

        $buyVol = (int) $buyVolRaw;
        $sellVol = (int) $sellVolRaw;
        $netVol = $buyVol - $sellVol;

        $buyVal = $this->parseOptionalDecimal($row['buy_value'] ?? null, 4) ?? '0.0000';
        $sellVal = $this->parseOptionalDecimal($row['sell_value'] ?? null, 4) ?? '0.0000';
        if (Dec::cmp($buyVal, '0') < 0 || Dec::cmp($sellVal, '0') < 0) {
            throw new InvalidArgumentException('buy_value and sell_value cannot be negative.');
        }

        $netVal = Dec::sub($buyVal, $sellVal, 4);
        $avgBuy = $this->parseOptionalDecimal($row['avg_buy_price'] ?? null, 6)
            ?? ($buyVol > 0 ? Dec::div($buyVal, (string) $buyVol, 6) : null);
        $avgSell = $this->parseOptionalDecimal($row['avg_sell_price'] ?? null, 6)
            ?? ($sellVol > 0 ? Dec::div($sellVal, (string) $sellVol, 6) : null);

        $broker = $brokersByMemberCode->get($brokerCode);
        $validCats = ['foreign_institutional', 'local_institutional', 'local_retail', 'global_broker'];
        $rawCat = strtolower(trim((string) ($row['broker_category'] ?? '')));
        $category = in_array($rawCat, $validCats, true)
            ? $rawCat
            : ($broker?->broker_category ?: 'local_retail');

        return [
            'instrument_id' => $inst->id,
            'broker_id' => $broker?->id,
            'broker_code' => $brokerCode,
            'broker_category' => $category,
            'trade_date' => $tradeDate,
            'buy_volume' => $buyVol,
            'sell_volume' => $sellVol,
            'net_volume' => $netVol,
            'buy_value' => $buyVal,
            'sell_value' => $sellVal,
            'net_value' => $netVal,
            'avg_buy_price' => $avgBuy,
            'avg_sell_price' => $avgSell,
            'currency' => $inst->currency ?: 'IDR',
            'data_source_id' => $source->id,
            'is_demo' => (bool) $source->is_demo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flushBatch(string $dataset, array $batch): int
    {
        match ($dataset) {
            'prices_daily' => InstrumentPriceDaily::query()->upsert(
                $batch,
                ['instrument_id', 'trade_date'],
                ['open', 'high', 'low', 'close', 'adj_close', 'volume', 'value_traded', 'data_source_id', 'is_demo', 'updated_at']
            ),
            'market_holidays' => MarketHoliday::query()->upsert(
                $batch,
                ['exchange_id', 'date'],
                ['name', 'is_half_day', 'updated_at']
            ),
            'financial_statements' => FinancialStatement::query()->upsert(
                $batch,
                ['instrument_id', 'period_type', 'fiscal_year'],
                [
                    'period_end_date', 'published_at', 'currency', 'is_financial_sector',
                    'revenue', 'gross_profit', 'operating_income', 'net_income', 'ebitda',
                    'extraordinary_items', 'total_assets', 'current_assets', 'cash_and_equivalents',
                    'total_liabilities', 'current_liabilities', 'total_debt', 'total_equity',
                    'operating_cash_flow', 'capex', 'free_cash_flow', 'dividends_paid',
                    'shares_outstanding', 'data_source_id', 'is_demo', 'updated_at',
                ]
            ),
            'corporate_actions' => CorporateAction::query()->upsert(
                $batch,
                ['instrument_id', 'action_type', 'ex_date'],
                ['record_date', 'payment_date', 'declared_currency', 'cash_amount', 'ratio_numerator', 'ratio_denominator', 'notes', 'data_source_id', 'is_demo', 'updated_at']
            ),
            'broker_summaries_daily' => BrokerSummaryDaily::query()->upsert(
                $batch,
                ['instrument_id', 'trade_date', 'broker_code'],
                [
                    'broker_id', 'broker_category', 'buy_volume', 'sell_volume', 'net_volume',
                    'buy_value', 'sell_value', 'net_value', 'avg_buy_price', 'avg_sell_price',
                    'currency', 'data_source_id', 'is_demo', 'updated_at',
                ]
            ),
        };

        return count($batch);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Instrument>  $instruments
     */
    private function resolveInstrument(array $row, $instruments): Instrument
    {
        $ex = strtoupper(trim((string) ($row['exchange_code'] ?? '')));
        $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
        $inst = $instruments->get("{$ex}:{$sym}");

        if (! $inst) {
            throw new InvalidArgumentException("Unknown instrument '{$ex}:{$sym}'.");
        }

        return $inst;
    }

    private function parseDate(mixed $val, string $field): string
    {
        $str = trim((string) $val);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) !== 1) {
            throw new InvalidArgumentException("Invalid {$field} '{$str}'. Expected YYYY-MM-DD.");
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $str, 'UTC');
        if (! $parsed || $parsed->format('Y-m-d') !== $str) {
            throw new InvalidArgumentException("Invalid calendar date for {$field}.");
        }

        return $str;
    }

    private function parsePositiveDecimal(mixed $val, string $field, int $scale): string
    {
        $str = trim((string) $val);
        if ($str === '' || ! is_numeric($str) || Dec::cmp($str, '0') <= 0) {
            throw new InvalidArgumentException("{$field} must be a positive number.");
        }

        return Dec::round($str, $scale);
    }

    private function parseOptionalDecimal(mixed $val, int $scale): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = trim((string) $val);
        if ($str === '') {
            return null;
        }
        if (! is_numeric($str)) {
            throw new InvalidArgumentException("Non-numeric decimal value '{$str}'.");
        }

        return Dec::round($str, $scale);
    }

    /**
     * @param  list<string>  $errors
     */
    private function pushError(array &$errors, string $msg): void
    {
        if (count($errors) < 20) {
            $errors[] = $msg;
        }
    }
}
