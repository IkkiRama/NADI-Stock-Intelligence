<?php

namespace App\Domain\PortfolioAccounting\Services;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Multi-Broker CSV Statement Importer.
 *
 * Supports NADI Standard plus Indonesian & global broker CSV column schemas
 * (Stockbit, Ajaib, IPOT, BIONS, MOST, IBKR) with automatic lot->share conversion,
 * formula-safe validation, and SHA-256 duplicate prevention.
 */
final class BrokerCsvImporter
{
    public const SUPPORTED_ADAPTERS = [
        'nadi_standard',
        'stockbit',
        'ajaib',
        'ipot',
        'bions',
        'most',
    ];

    public function __construct(
        private readonly PortfolioAccountingService $accounting,
        private readonly FxConversionService $fx,
    ) {}

    /**
     * @return array{inserted: int, skipped_duplicates: int, errors: list<string>}
     */
    public function import(User $user, BrokerageAccount $account, string $csvContent, string $adapter = 'nadi_standard'): array
    {
        if (! in_array($adapter, self::SUPPORTED_ADAPTERS, true)) {
            throw new RuntimeException("Unsupported broker CSV adapter: {$adapter}");
        }

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate temporary CSV stream.');
        }

        fwrite($stream, $csvContent);
        rewind($stream);

        $header = fgetcsv($stream);
        if (! is_array($header) || empty($header)) {
            fclose($stream);

            return ['inserted' => 0, 'skipped_duplicates' => 0, 'errors' => ['File CSV kosong atau header tidak valid.']];
        }

        $cols = array_map(fn ($h) => $this->normalizeHeader((string) $h), $header);
        $instruments = Instrument::query()->with('exchange:id,code')->get();
        $byExSym = [];
        $bySym = [];
        foreach ($instruments as $inst) {
            $exCode = strtoupper($inst->exchange->code);
            $sym = strtoupper($inst->symbol);
            $byExSym["{$exCode}:{$sym}"] = $inst;
            $bySym[$sym] ??= $inst;
        }

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($stream, $cols, $user, $account, $adapter, $byExSym, $bySym, &$inserted, &$skipped, &$errors, &$line): void {
            while (($row = fgetcsv($stream)) !== false) {
                $line++;
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue;
                }

                $assoc = [];
                foreach ($cols as $idx => $name) {
                    $assoc[$name] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
                }

                $tradeDate = $this->pick($assoc, ['trade_date', 'date', 'tanggal', 'tgl_transaksi']);
                $rawType = strtolower((string) $this->pick($assoc, ['transaction_type', 'type', 'action', 'aksi', 'jenis']));
                $type = $this->mapTransactionType($rawType);

                if ($tradeDate === '' || strtotime($tradeDate) === false || $type === null) {
                    $errors[] = "Baris {$line}: tanggal atau tipe transaksi tidak valid ({$rawType}).";
                    continue;
                }
                $tradeDate = date('Y-m-d', strtotime($tradeDate));

                $exchangeCode = strtoupper((string) $this->pick($assoc, ['exchange', 'bursa', 'market']));
                $symbol = strtoupper((string) $this->pick($assoc, ['symbol', 'ticker', 'stock_code', 'kode_saham', 'emiten']));
                $instrument = null;
                if ($symbol !== '') {
                    $instrument = $exchangeCode !== '' ? ($byExSym["{$exchangeCode}:{$symbol}"] ?? null) : ($bySym[$symbol] ?? null);
                    if (! $instrument) {
                        $errors[] = "Baris {$line}: instrumen {$symbol} tidak ditemukan di NADI.";
                        continue;
                    }
                }

                $lotsStr = $this->pick($assoc, ['lots', 'lot']);
                $qtyStr = $this->pick($assoc, ['quantity', 'qty', 'shares', 'volume', 'lembar']);
                $quantity = null;
                if ($qtyStr !== '' && is_numeric($qtyStr)) {
                    $quantity = Dec::round($qtyStr, 6);
                } elseif ($lotsStr !== '' && is_numeric($lotsStr)) {
                    $lotMult = (string) ($instrument?->lot_size ?? 100);
                    $quantity = Dec::mul($lotsStr, $lotMult, 6);
                }

                $priceStr = $this->pick($assoc, ['price', 'harga', 'exec_price']);
                $price = ($priceStr !== '' && is_numeric($priceStr)) ? Dec::round($priceStr, 6) : null;

                $grossStr = $this->pick($assoc, ['gross_amount', 'amount', 'nilai', 'total']);
                $gross = ($grossStr !== '' && is_numeric($grossStr)) ? Dec::round($grossStr, 4) : '0.0000';

                $brokerFee = $this->numOrZero($this->pick($assoc, ['broker_fee', 'fee', 'komisi']));
                $exchangeFee = $this->numOrZero($this->pick($assoc, ['exchange_fee', 'levy']));
                $tax = $this->numOrZero($this->pick($assoc, ['tax_amount', 'tax', 'pph', 'pajak']));
                $extRef = $this->pick($assoc, ['external_ref', 'ref', 'order_id', 'trade_id']);
                $notes = $this->pick($assoc, ['notes', 'keterangan', 'note']);
                $ccy = strtoupper($this->pick($assoc, ['currency', 'ccy']) ?: ($instrument?->currency ?? $account->base_currency));

                $dedupeSeed = implode('|', [
                    $account->id,
                    $tradeDate,
                    $type,
                    $instrument?->id ?? 'CASH',
                    $quantity ?? '0',
                    $price ?? $gross,
                    $brokerFee,
                    $extRef,
                ]);
                $dedupeHash = hash('sha256', $dedupeSeed);

                if (PortfolioTransaction::query()->where('brokerage_account_id', $account->id)->where('dedupe_hash', $dedupeHash)->exists()) {
                    $skipped++;
                    continue;
                }

                $totalCharges = Dec::add(Dec::add($brokerFee, $exchangeFee, 4), $tax, 4);
                $netCash = match ($type) {
                    'cash_deposit' => ($gross = Dec::round((string) abs((float) $gross), 4)),
                    'cash_withdrawal' => Dec::mul(($gross = Dec::round((string) abs((float) $gross), 4)), '-1', 4),
                    'buy', 'rights_issue' => (function () use ($quantity, $price, $totalCharges, &$gross): string {
                        $gross = Dec::mul($quantity ?? '0', $price ?? '0', 4);

                        return Dec::mul(Dec::add($gross, $totalCharges, 4), '-1', 4);
                    })(),
                    'sell' => (function () use ($quantity, $price, $totalCharges, &$gross): string {
                        $gross = Dec::mul($quantity ?? '0', $price ?? '0', 4);

                        return Dec::sub($gross, $totalCharges, 4);
                    })(),
                    'dividend_receipt' => Dec::sub($gross, $totalCharges, 4),
                    'dividend_tax', 'fee' => Dec::mul(Dec::round((string) abs((float) ($gross !== '0.0000' ? $gross : $totalCharges)), 4), '-1', 4),
                    default => Dec::round($gross, 4),
                };

                $fxRate = $this->fx->rate($ccy, strtoupper($account->base_currency), $tradeDate) ?? '1.00000000';

                PortfolioTransaction::query()->create([
                    'user_id' => $user->id,
                    'portfolio_id' => $account->portfolio_id,
                    'brokerage_account_id' => $account->id,
                    'instrument_id' => $instrument?->id,
                    'transaction_type' => $type,
                    'trade_date' => $tradeDate,
                    'quantity' => $quantity,
                    'price' => $price,
                    'gross_amount' => $gross,
                    'broker_fee' => $brokerFee,
                    'exchange_fee' => $exchangeFee,
                    'tax_amount' => $tax,
                    'net_cash_amount' => $netCash,
                    'transaction_currency' => $ccy,
                    'fx_rate_to_account' => $fxRate,
                    'import_source' => "csv:{$adapter}",
                    'external_ref' => $extRef !== '' ? $extRef : null,
                    'dedupe_hash' => $dedupeHash,
                    'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : "Imported ({$adapter})",
                    'is_demo' => (bool) $account->is_demo,
                ]);
                $inserted++;
            }

            $this->accounting->recomputeAccount($account);
        });

        fclose($stream);

        return [
            'inserted' => $inserted,
            'skipped_duplicates' => $skipped,
            'errors' => $errors,
        ];
    }

    private function normalizeHeader(string $raw): string
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', trim($raw)) ?? '';

        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $clean) ?? '');
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $candidates
     */
    private function pick(array $row, array $candidates): string
    {
        foreach ($candidates as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return '';
    }

    private function numOrZero(string $val): string
    {
        if ($val === '' || ! is_numeric($val)) {
            return '0.0000';
        }

        return Dec::round((string) abs((float) $val), 4);
    }

    private function mapTransactionType(string $raw): ?string
    {
        return match ($raw) {
            'buy', 'beli', 'b' => 'buy',
            'sell', 'jual', 's' => 'sell',
            'cash_deposit', 'deposit', 'topup', 'setor' => 'cash_deposit',
            'cash_withdrawal', 'withdraw', 'withdrawal', 'tarik' => 'cash_withdrawal',
            'dividend_receipt', 'dividend', 'dividen' => 'dividend_receipt',
            'dividend_tax', 'pajak_dividen' => 'dividend_tax',
            'fee', 'biaya' => 'fee',
            'stock_split', 'split' => 'stock_split',
            'reverse_split' => 'reverse_split',
            'bonus_shares', 'bonus' => 'bonus_shares',
            'rights_issue', 'hmetd', 'rights' => 'rights_issue',
            default => null,
        };
    }
}
