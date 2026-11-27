<?php

namespace App\Domain\BrokerIntelligence\Services;

use App\Domain\MarketData\Services\BatchFxLookup;
use App\Domain\MarketData\Services\CsvSanitizer;
use App\Models\Broker;
use App\Models\BrokerSummaryDaily;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;

/**
 * Broker Intelligence & Transaction Analytics Service.
 *
 * Combines:
 * 1. Official broker directory & exchange member metadata.
 * 2. EOD broker summary accumulation/distribution analytics (CR3/CR5, foreign vs institutional vs retail flow).
 * 3. User-scoped personal brokerage fee drag & execution efficiency analytics.
 * 4. Honest licensed market depth status disclosure (never fabricating Level-2 order book queues).
 */
final class BrokerIntelligenceService
{
    public const VALID_WINDOWS = [
        '1D' => 1,
        '5D' => 5,
        '20D' => 20,
    ];

    public const VALID_CATEGORIES = [
        'all',
        'foreign_institutional',
        'local_institutional',
        'local_retail',
        'global_broker',
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildWorkspace(User $user, array $options = []): array
    {
        $window = strtoupper((string) ($options['window'] ?? '5D'));
        if (! array_key_exists($window, self::VALID_WINDOWS)) {
            $window = '5D';
        }

        $category = strtolower((string) ($options['category'] ?? 'all'));
        if (! in_array($category, self::VALID_CATEGORIES, true)) {
            $category = 'all';
        }

        $currencies = config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']);
        $portfolio = $user->ensureDefaultPortfolio();
        $currency = strtoupper((string) ($options['currency'] ?? $portfolio->reporting_currency ?: 'IDR'));
        if (! in_array($currency, $currencies, true)) {
            $currency = $portfolio->reporting_currency ?: 'IDR';
        }

        $exchangeFilter = strtoupper((string) ($options['exchange'] ?? 'IDX'));
        if ($exchangeFilter === '') {
            $exchangeFilter = 'IDX';
        }

        $availableInstruments = $this->listCoveredInstruments();
        $requestedSymbol = strtoupper(trim((string) ($options['symbol'] ?? '')));

        $selectedInstrument = null;
        if ($requestedSymbol !== '') {
            $selectedInstrument = Instrument::query()
                ->with(['exchange:id,code,name', 'latestQuote:instrument_id,close,change_pct,is_demo'])
                ->where('symbol', $requestedSymbol)
                ->where('is_active', true)
                ->first();
        }

        if (! $selectedInstrument && ! empty($availableInstruments)) {
            $firstId = $availableInstruments[0]['id'];
            $selectedInstrument = Instrument::query()
                ->with(['exchange:id,code,name', 'latestQuote:instrument_id,close,change_pct,is_demo'])
                ->find($firstId);
        }

        $stockFlow = $selectedInstrument ? $this->analyzeInstrumentFlow($selectedInstrument, $window) : null;
        $marketFlowMovers = $this->buildMarketFlowMovers($window, $exchangeFilter);
        $personalAnalytics = $this->buildPersonalBrokerAnalytics($user, $currency);
        $directory = $this->buildBrokerDirectory($user, $category);

        return [
            'filters' => [
                'window' => $window,
                'symbol' => $selectedInstrument?->symbol ?? ($requestedSymbol ?: null),
                'exchange' => $selectedInstrument?->exchange?->code ?? $exchangeFilter,
                'category' => $category,
                'currency' => $currency,
            ],
            'windows' => array_keys(self::VALID_WINDOWS),
            'categories' => self::VALID_CATEGORIES,
            'currencies' => $currencies,
            'availableInstruments' => $availableInstruments,
            'stockFlow' => $stockFlow,
            'marketFlowMovers' => $marketFlowMovers,
            'directory' => $directory,
            'personalAnalytics' => $personalAnalytics,
            'marketDepthStatus' => [
                'enabled' => false,
                'licensed' => false,
                'reason_key' => 'broker_intel.depth.unlicensed_notice',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCoveredInstruments(): array
    {
        $latestDates = BrokerSummaryDaily::query()
            ->selectRaw('instrument_id, MAX(trade_date) as latest_date')
            ->groupBy('instrument_id')
            ->pluck('latest_date', 'instrument_id');

        if ($latestDates->isEmpty()) {
            return [];
        }

        $instruments = Instrument::query()
            ->with(['exchange:id,code', 'latestQuote:instrument_id,close,change_pct,is_demo'])
            ->whereIn('id', $latestDates->keys()->all())
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get();

        return $instruments->map(fn (Instrument $inst): array => [
            'id' => $inst->id,
            'exchange_code' => $inst->exchange?->code ?? 'IDX',
            'symbol' => $inst->symbol,
            'name' => $inst->name,
            'currency' => $inst->currency,
            'close' => $inst->latestQuote?->close !== null ? (string) $inst->latestQuote->close : null,
            'change_pct' => $inst->latestQuote?->change_pct !== null ? (string) $inst->latestQuote->change_pct : null,
            'latest_date' => substr((string) ($latestDates->get($inst->id) ?? ''), 0, 10),
            'is_demo' => (bool) ($inst->latestQuote?->is_demo ?? false),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyzeInstrumentFlow(Instrument $instrument, string $window = '5D'): ?array
    {
        $instrument->loadMissing(['exchange:id,code,name', 'latestQuote:instrument_id,close,change_pct,is_demo']);

        $windowKey = array_key_exists(strtoupper($window), self::VALID_WINDOWS) ? strtoupper($window) : '5D';
        $daysLimit = self::VALID_WINDOWS[$windowKey];

        $dates = BrokerSummaryDaily::query()
            ->where('instrument_id', $instrument->id)
            ->select('trade_date')
            ->distinct()
            ->orderByDesc('trade_date')
            ->limit($daysLimit)
            ->pluck('trade_date')
            ->map(fn ($d) => is_string($d) ? substr($d, 0, 10) : $d->toDateString())
            ->values()
            ->all();

        if (empty($dates)) {
            return null;
        }

        $maxDate = $dates[0];
        $minDate = $dates[count($dates) - 1];

        $rows = BrokerSummaryDaily::query()
            ->with(['broker:id,name,code,broker_category', 'dataSource:id,name'])
            ->where('instrument_id', $instrument->id)
            ->whereDate('trade_date', '>=', $minDate)
            ->whereDate('trade_date', '<=', $maxDate)
            ->orderBy('trade_date')
            ->orderBy('broker_code')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $byBroker = [];
        $byDate = [];
        $totalBuyValue = '0.0000';
        $totalSellValue = '0.0000';
        $foreignNet = '0.0000';
        $localInstNet = '0.0000';
        $retailNet = '0.0000';
        $isDemo = false;
        $sourceName = 'EOD Broker Summary';

        foreach ($rows as $row) {
            $isDemo = $isDemo || (bool) $row->is_demo;
            if ($row->dataSource?->name) {
                $sourceName = $row->dataSource->name;
            }

            $code = strtoupper((string) $row->broker_code);
            $cat = (string) ($row->broker_category ?: ($row->broker?->broker_category ?? 'local_retail'));
            $d = $row->trade_date->toDateString();

            if (! isset($byBroker[$code])) {
                $byBroker[$code] = [
                    'broker_code' => $code,
                    'broker_name' => $row->broker?->name ?? $code,
                    'broker_category' => $cat,
                    'buy_volume' => 0,
                    'sell_volume' => 0,
                    'net_volume' => 0,
                    'buy_value' => '0.0000',
                    'sell_value' => '0.0000',
                    'net_value' => '0.0000',
                ];
            }

            $buyVal = (string) $row->buy_value;
            $sellVal = (string) $row->sell_value;
            $netVal = (string) $row->net_value;

            $byBroker[$code]['buy_volume'] += (int) $row->buy_volume;
            $byBroker[$code]['sell_volume'] += (int) $row->sell_volume;
            $byBroker[$code]['net_volume'] += (int) $row->net_volume;
            $byBroker[$code]['buy_value'] = Dec::add($byBroker[$code]['buy_value'], $buyVal, 4);
            $byBroker[$code]['sell_value'] = Dec::add($byBroker[$code]['sell_value'], $sellVal, 4);
            $byBroker[$code]['net_value'] = Dec::add($byBroker[$code]['net_value'], $netVal, 4);

            $totalBuyValue = Dec::add($totalBuyValue, $buyVal, 4);
            $totalSellValue = Dec::add($totalSellValue, $sellVal, 4);

            if ($cat === 'foreign_institutional') {
                $foreignNet = Dec::add($foreignNet, $netVal, 4);
            } elseif ($cat === 'local_institutional') {
                $localInstNet = Dec::add($localInstNet, $netVal, 4);
            } else {
                $retailNet = Dec::add($retailNet, $netVal, 4);
            }

            if (! isset($byDate[$d])) {
                $byDate[$d] = [
                    'date' => $d,
                    'foreign_net' => '0.0000',
                    'local_inst_net' => '0.0000',
                    'retail_net' => '0.0000',
                    'total_turnover' => '0.0000',
                ];
            }

            if ($cat === 'foreign_institutional') {
                $byDate[$d]['foreign_net'] = Dec::add($byDate[$d]['foreign_net'], $netVal, 4);
            } elseif ($cat === 'local_institutional') {
                $byDate[$d]['local_inst_net'] = Dec::add($byDate[$d]['local_inst_net'], $netVal, 4);
            } else {
                $byDate[$d]['retail_net'] = Dec::add($byDate[$d]['retail_net'], $netVal, 4);
            }
            $byDate[$d]['total_turnover'] = Dec::add($byDate[$d]['total_turnover'], $buyVal, 4);
        }

        $brokersList = [];
        $buyers = [];
        $sellers = [];
        $totalPositiveNet = '0.0000';
        $totalNegativeNetAbs = '0.0000';

        foreach ($byBroker as $entry) {
            $entry['avg_buy_price'] = $entry['buy_volume'] > 0
                ? Dec::div($entry['buy_value'], (string) $entry['buy_volume'], 2)
                : null;
            $entry['avg_sell_price'] = $entry['sell_volume'] > 0
                ? Dec::div($entry['sell_value'], (string) $entry['sell_volume'], 2)
                : null;

            $brokersList[] = $entry;
            $cmp = Dec::cmp($entry['net_value'], '0');
            if ($cmp > 0) {
                $buyers[] = $entry;
                $totalPositiveNet = Dec::add($totalPositiveNet, $entry['net_value'], 4);
            } elseif ($cmp < 0) {
                $sellers[] = $entry;
                $totalNegativeNetAbs = Dec::add($totalNegativeNetAbs, Dec::mul($entry['net_value'], '-1', 4), 4);
            }
        }

        usort($buyers, fn ($a, $b) => Dec::cmp($b['net_value'], $a['net_value']));
        usort($sellers, fn ($a, $b) => Dec::cmp($a['net_value'], $b['net_value']));

        $top3BuyNet = '0.0000';
        $top5BuyNet = '0.0000';
        foreach (array_slice($buyers, 0, 5) as $idx => $b) {
            $top5BuyNet = Dec::add($top5BuyNet, $b['net_value'], 4);
            if ($idx < 3) {
                $top3BuyNet = Dec::add($top3BuyNet, $b['net_value'], 4);
            }
        }

        $top3SellNetAbs = '0.0000';
        $top5SellNetAbs = '0.0000';
        foreach (array_slice($sellers, 0, 5) as $idx => $s) {
            $absVal = Dec::mul($s['net_value'], '-1', 4);
            $top5SellNetAbs = Dec::add($top5SellNetAbs, $absVal, 4);
            if ($idx < 3) {
                $top3SellNetAbs = Dec::add($top3SellNetAbs, $absVal, 4);
            }
        }

        $cr3BuyPct = Dec::cmp($totalPositiveNet, '0') > 0 ? Dec::div($top3BuyNet, $totalPositiveNet, 4) : null;
        $cr5BuyPct = Dec::cmp($totalPositiveNet, '0') > 0 ? Dec::div($top5BuyNet, $totalPositiveNet, 4) : null;
        $cr3SellPct = Dec::cmp($totalNegativeNetAbs, '0') > 0 ? Dec::div($top3SellNetAbs, $totalNegativeNetAbs, 4) : null;
        $cr5SellPct = Dec::cmp($totalNegativeNetAbs, '0') > 0 ? Dec::div($top5SellNetAbs, $totalNegativeNetAbs, 4) : null;

        $top3NetImbalance = Dec::sub($top3BuyNet, $top3SellNetAbs, 4);
        $smartMoneyNet = Dec::add($foreignNet, $localInstNet, 4);
        $flowRatio = Dec::cmp($totalBuyValue, '0') > 0
            ? Dec::div($top3NetImbalance, $totalBuyValue, 6)
            : '0.000000';

        $regime = $this->classifyRegime(
            $cr3BuyPct,
            $cr3SellPct,
            $top3NetImbalance,
            $smartMoneyNet,
        );

        ksort($byDate);

        return [
            'instrument' => [
                'id' => $instrument->id,
                'exchange_code' => $instrument->exchange?->code ?? 'IDX',
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'currency' => $instrument->currency,
                'close' => $instrument->latestQuote?->close !== null ? (string) $instrument->latestQuote->close : null,
                'change_pct' => $instrument->latestQuote?->change_pct !== null ? (string) $instrument->latestQuote->change_pct : null,
            ],
            'window' => $windowKey,
            'window_start' => $minDate,
            'window_end' => $maxDate,
            'as_of' => $maxDate,
            'trading_days_count' => count($dates),
            'currency' => $instrument->currency ?: 'IDR',
            'is_demo' => $isDemo,
            'source_name' => $sourceName,
            'summary' => [
                'regime' => $regime,
                'total_buy_value' => $totalBuyValue,
                'total_sell_value' => $totalSellValue,
                'top3_buy_net' => $top3BuyNet,
                'top3_sell_net_abs' => $top3SellNetAbs,
                'top3_net_imbalance' => $top3NetImbalance,
                'cr3_buy_pct' => $cr3BuyPct,
                'cr3_sell_pct' => $cr3SellPct,
                'cr5_buy_pct' => $cr5BuyPct,
                'cr5_sell_pct' => $cr5SellPct,
                'foreign_net_value' => $foreignNet,
                'local_inst_net_value' => $localInstNet,
                'retail_net_value' => $retailNet,
                'smart_money_net_value' => $smartMoneyNet,
                'flow_ratio' => $flowRatio,
                'active_brokers_count' => count($brokersList),
            ],
            'top_buyers' => array_slice($buyers, 0, 10),
            'top_sellers' => array_slice($sellers, 0, 10),
            'daily_trend' => array_values($byDate),
        ];
    }

    /**
     * Compact card summary for `/stocks/{exchange}/{symbol}`.
     *
     * @return array<string, mixed>|null
     */
    public function buildCompactSummaryForStock(Instrument $instrument): ?array
    {
        $flow = $this->analyzeInstrumentFlow($instrument, '5D');
        if (! $flow) {
            return null;
        }

        return [
            'symbol' => $flow['instrument']['symbol'],
            'window' => $flow['window'],
            'as_of' => $flow['as_of'],
            'trading_days_count' => $flow['trading_days_count'],
            'currency' => $flow['currency'],
            'is_demo' => $flow['is_demo'],
            'regime' => $flow['summary']['regime'],
            'cr3_buy_pct' => $flow['summary']['cr3_buy_pct'],
            'cr3_sell_pct' => $flow['summary']['cr3_sell_pct'],
            'foreign_net_value' => $flow['summary']['foreign_net_value'],
            'smart_money_net_value' => $flow['summary']['smart_money_net_value'],
            'top_buyer' => $flow['top_buyers'][0] ?? null,
            'top_seller' => $flow['top_sellers'][0] ?? null,
        ];
    }

    /**
     * @return array{top_accumulated: list<array<string, mixed>>, top_distributed: list<array<string, mixed>>}
     */
    public function buildMarketFlowMovers(string $window = '5D', string $exchangeCode = 'IDX'): array
    {
        $covered = $this->listCoveredInstruments();
        $accumulated = [];
        $distributed = [];

        foreach ($covered as $item) {
            if ($exchangeCode !== 'ALL' && strtoupper($item['exchange_code']) !== strtoupper($exchangeCode)) {
                continue;
            }

            $inst = Instrument::query()->with(['exchange:id,code', 'latestQuote:instrument_id,close,change_pct,is_demo'])->find($item['id']);
            if (! $inst) {
                continue;
            }

            $flow = $this->analyzeInstrumentFlow($inst, $window);
            if (! $flow) {
                continue;
            }

            $s = $flow['summary'];
            $entry = [
                'symbol' => $inst->symbol,
                'exchange_code' => $flow['instrument']['exchange_code'],
                'name' => $inst->name,
                'currency' => $flow['currency'],
                'close' => $flow['instrument']['close'],
                'change_pct' => $flow['instrument']['change_pct'],
                'regime' => $s['regime'],
                'smart_money_net_value' => $s['smart_money_net_value'],
                'foreign_net_value' => $s['foreign_net_value'],
                'top3_net_imbalance' => $s['top3_net_imbalance'],
                'cr3_buy_pct' => $s['cr3_buy_pct'],
                'cr3_sell_pct' => $s['cr3_sell_pct'],
                'as_of' => $flow['as_of'],
                'is_demo' => $flow['is_demo'],
            ];

            if (Dec::cmp($s['smart_money_net_value'], '0') >= 0) {
                $accumulated[] = $entry;
            } else {
                $distributed[] = $entry;
            }
        }

        usort($accumulated, fn ($a, $b) => Dec::cmp($b['smart_money_net_value'], $a['smart_money_net_value']));
        usort($distributed, fn ($a, $b) => Dec::cmp($a['smart_money_net_value'], $b['smart_money_net_value']));

        return [
            'top_accumulated' => array_slice($accumulated, 0, 8),
            'top_distributed' => array_slice($distributed, 0, 8),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildBrokerDirectory(User $user, string $category = 'all'): array
    {
        $connectedCounts = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->whereNotNull('broker_id')
            ->selectRaw('broker_id, COUNT(*) as cnt')
            ->groupBy('broker_id')
            ->pluck('cnt', 'broker_id');

        $query = Broker::query()
            ->where('is_active', true)
            ->orderBy('country_code')
            ->orderBy('broker_category')
            ->orderBy('name');

        if ($category !== 'all' && in_array($category, self::VALID_CATEGORIES, true)) {
            $query->where('broker_category', $category);
        }

        return $query->get()->map(fn (Broker $b): array => [
            'id' => $b->id,
            'code' => $b->code,
            'name' => $b->name,
            'exchange_member_code' => $b->exchange_member_code,
            'country_code' => $b->country_code,
            'default_currency' => $b->default_currency,
            'csv_adapter' => $b->csv_adapter,
            'broker_category' => $b->broker_category ?: 'local_retail',
            'typical_buy_fee_pct' => $b->typical_buy_fee_pct !== null ? (string) $b->typical_buy_fee_pct : null,
            'typical_sell_fee_pct' => $b->typical_sell_fee_pct !== null ? (string) $b->typical_sell_fee_pct : null,
            'supports_live_sync' => (bool) $b->supports_live_sync,
            'notes' => $b->notes,
            'connected_accounts' => (int) ($connectedCounts->get($b->id) ?? 0),
        ])->values()->all();
    }

    /**
     * User-isolated personal brokerage fee drag and execution analytics.
     *
     * @return array<string, mixed>
     */
    public function buildPersonalBrokerAnalytics(User $user, string $targetCurrency = 'IDR'): array
    {
        $accounts = BrokerageAccount::query()
            ->with('broker:id,code,name,broker_category,exchange_member_code')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            return [
                'reporting_currency' => $targetCurrency,
                'summary' => [
                    'total_accounts' => 0,
                    'total_trades' => 0,
                    'total_traded_value_rep' => '0.0000',
                    'total_charges_rep' => '0.0000',
                    'weighted_fee_pct' => null,
                    'weighted_fee_bps' => null,
                    'contains_demo' => false,
                ],
                'accounts' => [],
            ];
        }

        $txRows = PortfolioTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('brokerage_account_id', $accounts->pluck('id')->all())
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->orderBy('trade_date')
            ->get();

        $fxLookup = BatchFxLookup::load();

        $acctStats = [];
        foreach ($accounts as $acct) {
            $acctStats[$acct->id] = [
                'account_id' => $acct->id,
                'account_name' => $acct->account_name,
                'account_label' => $acct->account_label,
                'broker_code' => $acct->broker?->code ?? 'MANUAL',
                'broker_name' => $acct->broker?->name ?? 'Manual Account',
                'member_code' => $acct->broker?->exchange_member_code,
                'broker_category' => $acct->broker?->broker_category ?? 'local_retail',
                'base_currency' => $acct->base_currency,
                'cost_basis_method' => $acct->cost_basis_method,
                'trade_count' => 0,
                'buy_count' => 0,
                'sell_count' => 0,
                'dividend_count' => 0,
                'gross_traded_value_rep' => '0.0000',
                'broker_fees_rep' => '0.0000',
                'exchange_fees_rep' => '0.0000',
                'taxes_rep' => '0.0000',
                'total_charges_rep' => '0.0000',
                'manual_tx_count' => 0,
                'csv_tx_count' => 0,
                'last_reconciled_at' => $acct->last_reconciled_at?->toIso8601String(),
                'is_demo' => (bool) $acct->is_demo,
            ];
        }

        $totalTrades = 0;
        $totalTradedValRep = '0.0000';
        $totalChargesRep = '0.0000';
        $containsDemo = false;

        foreach ($txRows as $tx) {
            $aid = $tx->brokerage_account_id;
            if (! isset($acctStats[$aid])) {
                continue;
            }

            $containsDemo = $containsDemo || (bool) $tx->is_demo;
            $d = $tx->trade_date->toDateString();
            $ccy = strtoupper((string) $tx->transaction_currency);
            $fx = $fxLookup->resolve($ccy, $targetCurrency, $d) ?? '1.00000000';

            $grossRep = Dec::mul((string) $tx->gross_amount, $fx, 4);
            $brokerFeeRep = Dec::mul((string) $tx->broker_fee, $fx, 4);
            $exchangeFeeRep = Dec::mul((string) $tx->exchange_fee, $fx, 4);
            $taxRep = Dec::mul((string) $tx->tax_amount, $fx, 4);
            $chargesRep = Dec::add(Dec::add($brokerFeeRep, $exchangeFeeRep, 4), $taxRep, 4);

            $acctStats[$aid]['broker_fees_rep'] = Dec::add($acctStats[$aid]['broker_fees_rep'], $brokerFeeRep, 4);
            $acctStats[$aid]['exchange_fees_rep'] = Dec::add($acctStats[$aid]['exchange_fees_rep'], $exchangeFeeRep, 4);
            $acctStats[$aid]['taxes_rep'] = Dec::add($acctStats[$aid]['taxes_rep'], $taxRep, 4);
            $acctStats[$aid]['total_charges_rep'] = Dec::add($acctStats[$aid]['total_charges_rep'], $chargesRep, 4);

            if (str_starts_with((string) $tx->import_source, 'csv')) {
                $acctStats[$aid]['csv_tx_count']++;
            } else {
                $acctStats[$aid]['manual_tx_count']++;
            }

            if (in_array($tx->transaction_type, ['buy', 'sell', 'rights_issue'], true)) {
                $acctStats[$aid]['trade_count']++;
                if ($tx->transaction_type === 'sell') {
                    $acctStats[$aid]['sell_count']++;
                } else {
                    $acctStats[$aid]['buy_count']++;
                }
                $acctStats[$aid]['gross_traded_value_rep'] = Dec::add($acctStats[$aid]['gross_traded_value_rep'], $grossRep, 4);

                $totalTrades++;
                $totalTradedValRep = Dec::add($totalTradedValRep, $grossRep, 4);
            } elseif ($tx->transaction_type === 'dividend_receipt') {
                $acctStats[$aid]['dividend_count']++;
            }

            $totalChargesRep = Dec::add($totalChargesRep, $chargesRep, 4);
        }

        $finalAccounts = [];
        foreach ($acctStats as $row) {
            $containsDemo = $containsDemo || $row['is_demo'];
            if (Dec::cmp($row['gross_traded_value_rep'], '0') > 0) {
                $pct = Dec::div($row['total_charges_rep'], $row['gross_traded_value_rep'], 6);
                $row['effective_fee_pct'] = $pct;
                $row['effective_fee_bps'] = Dec::mul($pct, '10000', 2);
            } else {
                $row['effective_fee_pct'] = null;
                $row['effective_fee_bps'] = null;
            }
            $finalAccounts[] = $row;
        }

        $weightedPct = Dec::cmp($totalTradedValRep, '0') > 0
            ? Dec::div($totalChargesRep, $totalTradedValRep, 6)
            : null;
        $weightedBps = $weightedPct !== null ? Dec::mul($weightedPct, '10000', 2) : null;

        return [
            'reporting_currency' => $targetCurrency,
            'summary' => [
                'total_accounts' => count($finalAccounts),
                'total_trades' => $totalTrades,
                'total_traded_value_rep' => $totalTradedValRep,
                'total_charges_rep' => $totalChargesRep,
                'weighted_fee_pct' => $weightedPct,
                'weighted_fee_bps' => $weightedBps,
                'contains_demo' => $containsDemo,
            ],
            'accounts' => $finalAccounts,
        ];
    }

    /**
     * Formula-injection-safe UTF-8 BOM CSV string for EOD broker flow.
     *
     * @param  array<string, mixed>  $stockFlow
     */
    public function exportStockFlowCsv(array $stockFlow): string
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, "\xEF\xBB\xBF");

        $meta = [
            ['Dataset', 'EOD Broker Summary Flow'],
            ['Exchange', (string) ($stockFlow['instrument']['exchange_code'] ?? '')],
            ['Symbol', (string) ($stockFlow['instrument']['symbol'] ?? '')],
            ['Window', (string) ($stockFlow['window'] ?? '5D')],
            ['DateRange', ($stockFlow['window_start'] ?? '').' .. '.($stockFlow['window_end'] ?? '')],
            ['Currency', (string) ($stockFlow['currency'] ?? 'IDR')],
            ['Regime', (string) ($stockFlow['summary']['regime'] ?? 'neutral')],
            ['CR3BuyPct', (string) ($stockFlow['summary']['cr3_buy_pct'] ?? '')],
            ['CR3SellPct', (string) ($stockFlow['summary']['cr3_sell_pct'] ?? '')],
            ['SmartMoneyNetValue', (string) ($stockFlow['summary']['smart_money_net_value'] ?? '0')],
            ['ContainsDemoData', ($stockFlow['is_demo'] ?? false) ? 'YES' : 'NO'],
        ];

        foreach ($meta as $m) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], $m));
        }

        fputcsv($handle, []);
        fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], [
            'Side', 'Rank', 'BrokerCode', 'BrokerName', 'Category',
            'BuyVolume', 'SellVolume', 'NetVolume',
            'BuyValue', 'SellValue', 'NetValue', 'AvgBuyPrice', 'AvgSellPrice',
        ]));

        foreach (['BUYER' => $stockFlow['top_buyers'] ?? [], 'SELLER' => $stockFlow['top_sellers'] ?? []] as $side => $list) {
            foreach ($list as $idx => $b) {
                fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], [
                    $side,
                    (string) ($idx + 1),
                    (string) ($b['broker_code'] ?? ''),
                    (string) ($b['broker_name'] ?? ''),
                    (string) ($b['broker_category'] ?? ''),
                    (string) ($b['buy_volume'] ?? 0),
                    (string) ($b['sell_volume'] ?? 0),
                    (string) ($b['net_volume'] ?? 0),
                    (string) ($b['buy_value'] ?? '0'),
                    (string) ($b['sell_value'] ?? '0'),
                    (string) ($b['net_value'] ?? '0'),
                    (string) ($b['avg_buy_price'] ?? ''),
                    (string) ($b['avg_sell_price'] ?? ''),
                ]));
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function classifyRegime(
        ?string $cr3BuyPct,
        ?string $cr3SellPct,
        string $top3NetImbalance,
        string $smartMoneyNet,
    ): string {
        $imbCmp = Dec::cmp($top3NetImbalance, '0');
        $smCmp = Dec::cmp($smartMoneyNet, '0');

        if ($cr3BuyPct !== null && Dec::cmp($cr3BuyPct, '0.55') >= 0 && $imbCmp > 0 && $smCmp > 0) {
            return 'strong_accumulation';
        }
        if ($cr3SellPct !== null && Dec::cmp($cr3SellPct, '0.55') >= 0 && $imbCmp < 0 && $smCmp < 0) {
            return 'strong_distribution';
        }

        $diff = ($cr3BuyPct !== null && $cr3SellPct !== null)
            ? Dec::sub($cr3BuyPct, $cr3SellPct, 4)
            : '0.0000';

        if ($imbCmp > 0 && (Dec::cmp($diff, '0.05') >= 0 || $smCmp > 0)) {
            return 'accumulation';
        }
        if ($imbCmp < 0 && (Dec::cmp($diff, '-0.05') <= 0 || $smCmp < 0)) {
            return 'distribution';
        }

        return 'neutral';
    }
}
