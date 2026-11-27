<?php

namespace App\Domain\Reports\Services;

use App\Domain\Dividends\Services\DividendIncomeService;
use App\Domain\MarketData\Services\BatchFxLookup;
use App\Domain\Performance\Services\BenchmarkComparisonService;
use App\Domain\Performance\Services\PerformanceAttributionService;
use App\Domain\Performance\Services\PortfolioNavSeriesService;
use App\Domain\Performance\Services\TwrCalculator;
use App\Domain\Performance\Services\XirrCalculator;
use App\Domain\Portfolio\Services\PortfolioAnalyticsCommandCenterService;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\Risk\Services\HoldingCorrelationAndExposureService;
use App\Domain\Risk\Services\PortfolioRebalancingService;
use App\Domain\Risk\Services\PortfolioRiskAnalyticsService;
use App\Models\BrokerageAccount;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Institutional Report Assembly Service.
 *
 * Composes verified domain outputs (Valuation, Command Center, TWR/XIRR/Attribution,
 * Dividends, Risk/Rebalancing, and Ledger Register) into structured report payloads
 * suitable for screen preview, printable HTML statements, CSV, and JSON exports.
 */
final class PortfolioReportBuilderService
{
    public const REPORT_TYPES = [
        'executive_statement',
        'performance_brief',
        'dividend_tax_schedule',
        'risk_rebalancing_plan',
        'tax_realized_ledger',
    ];

    public const VALID_RANGES = ['1M', '3M', '6M', 'YTD', '1Y', 'ALL'];

    public function __construct(
        private readonly PortfolioValuationService $valuation,
        private readonly PortfolioAnalyticsCommandCenterService $commandCenter,
        private readonly PortfolioNavSeriesService $navSeries,
        private readonly TwrCalculator $twrCalculator,
        private readonly XirrCalculator $xirrCalculator,
        private readonly PerformanceAttributionService $attributionService,
        private readonly BenchmarkComparisonService $benchmarkService,
        private readonly DividendIncomeService $dividendService,
        private readonly PortfolioRiskAnalyticsService $riskService,
        private readonly HoldingCorrelationAndExposureService $correlationService,
        private readonly PortfolioRebalancingService $rebalancingService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(User $user, Portfolio $portfolio, string $reportType, array $options = []): array
    {
        $type = in_array($reportType, self::REPORT_TYPES, true) ? $reportType : 'executive_statement';

        $accountId = isset($options['account_id']) && is_numeric($options['account_id'])
            ? (int) $options['account_id']
            : null;
        if ($accountId !== null) {
            $owned = BrokerageAccount::query()
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->where('portfolio_id', $portfolio->id)
                ->exists();
            if (! $owned) {
                $accountId = null;
            }
        }

        $currencies = config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']);
        $currency = strtoupper((string) ($options['currency'] ?? $portfolio->reporting_currency ?: 'IDR'));
        if (! in_array($currency, $currencies, true)) {
            $currency = $portfolio->reporting_currency ?: 'IDR';
        }

        $method = (string) ($options['method'] ?? $portfolio->cost_basis_method ?: 'weighted_average');
        if (! in_array($method, ['weighted_average', 'fifo'], true)) {
            $method = $portfolio->cost_basis_method ?: 'weighted_average';
        }

        $range = strtoupper((string) ($options['range'] ?? '1Y'));
        if (! in_array($range, self::VALID_RANGES, true)) {
            $range = '1Y';
        }

        $benchmarkCode = strtoupper((string) ($options['benchmark'] ?? config('nadi.defaults.benchmark', 'IHSG')));
        $benchmarkCcyMode = (string) ($options['benchmark_ccy_mode'] ?? 'reporting_aligned');
        if (! in_array($benchmarkCcyMode, ['reporting_aligned', 'native_index'], true)) {
            $benchmarkCcyMode = 'reporting_aligned';
        }

        $user->loadMissing('settings');
        $tz = $user->settings?->timezone ?? config('nadi.defaults.timezone', 'Asia/Jakarta');

        $valuation = $this->valuation->summarize($user, $portfolio, $accountId, $currency, $method);
        $summary = $valuation['summary'];

        $payload = [
            'report_type' => $type,
            'meta' => [
                'generated_at_utc' => now('UTC')->toIso8601String(),
                'display_timezone' => $tz,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'portfolio_name' => $portfolio->name,
                'account_id' => $accountId,
                'reporting_currency' => $currency,
                'cost_basis_method' => $method,
                'range' => $range,
                'benchmark' => $benchmarkCode,
                'benchmark_ccy_mode' => $benchmarkCcyMode,
                'as_of' => $summary['as_of'] ?? null,
                'is_demo' => (bool) ($summary['is_demo'] ?? false),
            ],
            'valuation' => $valuation,
            'disclaimers' => [
                'eod_basis' => 'Penilaian harga menggunakan data End-of-Day (EOD) terbaru dan kurs referensi historis yang tersedia.',
                'not_advice' => 'Laporan ini adalah dokumen analitik internal dan bukan merupakan nasihat investasi atau laporan pajak resmi yang diaudit.',
            ],
            'row_count' => count($valuation['consolidatedHoldings'] ?? []),
        ];

        if ((bool) ($summary['is_demo'] ?? false)) {
            $payload['disclaimers']['demo_notice'] = 'Sebagian atau seluruh akun/harga menggunakan data simulasi DEMO NADI dan bukan kepemilikan broker riil.';
        }

        switch ($type) {
            case 'executive_statement':
                $commandCenter = $this->commandCenter->analyze($valuation, $portfolio->mergedConcentrationThresholds());
                $payload['command_center'] = $commandCenter;
                $payload['row_count'] = count($valuation['consolidatedHoldings'] ?? []);
                break;

            case 'performance_brief':
                $window = $this->navSeries->buildWindowSeries($user, $portfolio, $accountId, $currency, $method, $range);
                $twr = $this->twrCalculator->calculate($window);
                $xirr = $this->xirrCalculator->calculateForWindow($window);
                $attribution = $this->attributionService->attribute($user, $portfolio, $window, $twr, $accountId);
                $benchmark = $this->benchmarkService->compare($window, $twr, $benchmarkCode, $benchmarkCcyMode);

                $payload['window'] = [
                    'has_data' => $window['has_data'],
                    'start_date' => $window['start_date'],
                    'end_date' => $window['end_date'],
                    'begin_equity' => $window['begin_equity'],
                    'end_equity' => $window['end_equity'],
                    'is_demo' => $window['is_demo'],
                ];
                $payload['twr'] = $twr;
                $payload['xirr'] = $xirr;
                $payload['attribution'] = $attribution;
                $payload['benchmark'] = $benchmark;
                $payload['meta']['is_demo'] = $payload['meta']['is_demo'] || (bool) ($window['is_demo'] ?? false);
                $payload['row_count'] = count($attribution['by_stock'] ?? []);
                $payload['disclaimers']['twr_xirr'] = 'TWR mengukur kinerja portofolio bebas waktu arus kas; XIRR mengukur imbal hasil tertimbang ukuran & tanggal setoran/penarikan.';
                break;

            case 'dividend_tax_schedule':
                $dividends = $this->dividendService->analyze($user, $portfolio, $valuation, $accountId);
                $payload['dividends'] = $dividends;
                $payload['meta']['is_demo'] = $payload['meta']['is_demo'] || (bool) ($dividends['is_demo'] ?? false);
                $payload['row_count'] = count($dividends['ledger_history'] ?? []) + count($dividends['holdings'] ?? []);
                $payload['disclaimers']['dividend_estimate'] = 'Proyeksi dividen 12 bulan ke depan adalah estimasi dan dapat berubah mengikuti keputusan RUPS; angka terealisasi berasal dari buku besar transaksi.';
                break;

            case 'risk_rebalancing_plan':
                $window = $this->navSeries->buildWindowSeries($user, $portfolio, $accountId, $currency, $method, $range);
                $twr = $this->twrCalculator->calculate($window);
                $benchmark = $this->benchmarkService->compare($window, $twr, $benchmarkCode, 'reporting_aligned');

                $prefs = $portfolio->mergedRiskRebalancePrefs();
                $defaultRf = config("nadi.risk_free_rates.{$currency}") ?? config('nadi.risk_free_rates.DEFAULT', '0.0450');
                $rfAnnual = $prefs['risk_free_rate_pct'] !== null
                    ? number_format(((float) $prefs['risk_free_rate_pct']) / 100.0, 6, '.', '')
                    : (string) $defaultRf;

                $risk = $this->riskService->analyze($window, $twr, $benchmark, (string) ($summary['total_equity'] ?? '0'), $rfAnnual);
                $exposure = $this->correlationService->analyze($valuation, $window['end_date'] ?? null);
                $rebalance = $this->rebalancingService->simulate($user, $portfolio, $valuation, $prefs);

                $payload['risk'] = $risk;
                $payload['exposure'] = $exposure;
                $payload['rebalance'] = $rebalance;
                $payload['row_count'] = count($rebalance['rows'] ?? []);
                $payload['disclaimers']['rebalance_sim'] = 'Rencana rebalancing adalah simulasi berbasis lot dan estimasi biaya bursa; NADI tidak mengeksekusi order langsung ke broker.';
                break;

            case 'tax_realized_ledger':
                $ledger = $this->buildLedgerRegister($user, $portfolio, $accountId, $currency, $range);
                $payload['ledger'] = $ledger;
                $payload['row_count'] = count($ledger['transactions']);
                $payload['disclaimers']['tax_notice'] = 'Ringkasan transaksi dan biaya ini disediakan untuk pencatatan administratif dan rekonsiliasi internal, bukan pengganti SPT atau dokumen pajak resmi.';
                break;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLedgerRegister(
        User $user,
        Portfolio $portfolio,
        ?int $accountId,
        string $targetCcy,
        string $range,
    ): array {
        $accounts = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->where('id', $accountId))
            ->get(['id', 'account_name', 'base_currency'])
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return [
                'start_date' => null,
                'end_date' => null,
                'totals' => [
                    'deposits_rep' => '0.0000',
                    'withdrawals_rep' => '0.0000',
                    'buy_gross_rep' => '0.0000',
                    'sell_gross_rep' => '0.0000',
                    'dividends_net_rep' => '0.0000',
                    'fees_taxes_rep' => '0.0000',
                ],
                'transactions' => [],
            ];
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $cutoffDate = match ($range) {
            '1M' => $today->subMonthsNoOverflow(1)->toDateString(),
            '3M' => $today->subMonthsNoOverflow(3)->toDateString(),
            '6M' => $today->subMonthsNoOverflow(6)->toDateString(),
            'YTD' => $today->startOfYear()->toDateString(),
            '1Y' => $today->subYearsNoOverflow(1)->toDateString(),
            default => null,
        };

        $txQuery = PortfolioTransaction::query()
            ->with(['instrument.exchange:id,code', 'account:id,account_name'])
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('brokerage_account_id', $accounts->keys()->all())
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->orderByDesc('trade_date')
            ->orderByDesc('id');

        if ($cutoffDate !== null) {
            $txQuery->whereDate('trade_date', '>=', $cutoffDate);
        }

        $rows = $txQuery->limit(1000)->get();
        $fxLookup = BatchFxLookup::load();

        $totals = [
            'deposits_rep' => '0.0000',
            'withdrawals_rep' => '0.0000',
            'buy_gross_rep' => '0.0000',
            'sell_gross_rep' => '0.0000',
            'dividends_net_rep' => '0.0000',
            'fees_taxes_rep' => '0.0000',
        ];

        $transactions = [];
        foreach ($rows as $tx) {
            $d = $tx->trade_date->toDateString();
            $txCcy = strtoupper((string) $tx->transaction_currency);
            $fx = $fxLookup->resolve($txCcy, $targetCcy, $d) ?? '1.00000000';

            $grossRep = Dec::mul((string) $tx->gross_amount, $fx, 4);
            $chargesTx = Dec::add(Dec::add((string) $tx->broker_fee, (string) $tx->exchange_fee, 4), (string) $tx->tax_amount, 4);
            $chargesRep = Dec::mul($chargesTx, $fx, 4);
            $netCashRep = Dec::mul((string) $tx->net_cash_amount, $fx, 4);

            $totals['fees_taxes_rep'] = Dec::add($totals['fees_taxes_rep'], $chargesRep, 4);

            switch ($tx->transaction_type) {
                case 'cash_deposit':
                    $totals['deposits_rep'] = Dec::add($totals['deposits_rep'], $netCashRep, 4);
                    break;
                case 'cash_withdrawal':
                    $totals['withdrawals_rep'] = Dec::add($totals['withdrawals_rep'], Dec::mul($netCashRep, '-1', 4), 4);
                    break;
                case 'buy':
                case 'rights_issue':
                    $totals['buy_gross_rep'] = Dec::add($totals['buy_gross_rep'], $grossRep, 4);
                    break;
                case 'sell':
                    $totals['sell_gross_rep'] = Dec::add($totals['sell_gross_rep'], $grossRep, 4);
                    break;
                case 'dividend_receipt':
                    $totals['dividends_net_rep'] = Dec::add($totals['dividends_net_rep'], $netCashRep, 4);
                    break;
                case 'dividend_tax':
                    $totals['dividends_net_rep'] = Dec::add($totals['dividends_net_rep'], $netCashRep, 4);
                    break;
            }

            $transactions[] = [
                'id' => $tx->id,
                'trade_date' => $d,
                'account_name' => $tx->account?->account_name ?? '',
                'transaction_type' => $tx->transaction_type,
                'exchange_code' => $tx->instrument?->exchange?->code ?? '',
                'symbol' => $tx->instrument?->symbol ?? '',
                'quantity' => $tx->quantity !== null ? (string) $tx->quantity : null,
                'price' => $tx->price !== null ? (string) $tx->price : null,
                'gross_amount' => (string) $tx->gross_amount,
                'broker_fee' => (string) $tx->broker_fee,
                'exchange_fee' => (string) $tx->exchange_fee,
                'tax_amount' => (string) $tx->tax_amount,
                'net_cash_amount' => (string) $tx->net_cash_amount,
                'transaction_currency' => $txCcy,
                'net_cash_reporting' => $netCashRep,
                'notes' => $tx->notes,
                'is_demo' => (bool) $tx->is_demo,
            ];
        }

        return [
            'start_date' => $cutoffDate,
            'end_date' => $today->toDateString(),
            'totals' => $totals,
            'transactions' => $transactions,
        ];
    }
}
