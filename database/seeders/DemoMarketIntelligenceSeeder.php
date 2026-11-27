<?php

namespace Database\Seeders;

use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use App\Domain\MarketData\Support\SeededRandom;
use App\Models\CorporateAction;
use App\Models\DataSource;
use App\Models\FinancialStatement;
use App\Models\FxRateDaily;
use App\Models\Instrument;
use App\Models\InstrumentRegulatoryStatus;
use App\Models\InstrumentScore;
use App\Models\InstrumentScoreHistory;
use App\Models\StockSafetyAssessment;
use App\Models\StockSafetyHistory;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoMarketIntelligenceSeeder extends Seeder
{
    public function run(RefreshMarketIntelligence $refreshIntelligence): void
    {
        if (! config('nadi.market_data.demo.enabled', true)) {
            return;
        }

        $source = DataSource::query()->where('code', 'demo')->first();
        if (! $source || ! $source->is_enabled) {
            return;
        }

        $now = now('UTC');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $fxDate = $today->subDays(1)->toDateString();

        // 1. Seed FX rates (deterministic demo reference rates)
        $fxPairs = [
            ['USD', 'IDR', '15850.00000000'],
            ['SGD', 'IDR', '11820.00000000'],
            ['CNY', 'IDR', '2190.00000000'],
            ['HKD', 'IDR', '2035.00000000'],
            ['USD', 'HKD', '7.78000000'],
        ];

        $fxRows = [];
        foreach ($fxPairs as [$base, $quote, $rate]) {
            $fxRows[] = [
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_date' => $fxDate,
                'rate' => $rate,
                'data_source_id' => $source->id,
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        FxRateDaily::query()->upsert(
            $fxRows,
            ['base_currency', 'quote_currency', 'rate_date'],
            ['rate', 'data_source_id', 'is_demo', 'updated_at']
        );

        // 2. Seed FY 2024 & FY 2025 demo financial statements + corporate actions
        $instruments = Instrument::query()->with(['exchange', 'latestQuote'])->where('is_active', true)->get();
        $stmtRows = [];
        $actionRows = [];

        foreach ($instruments as $inst) {
            $rng = SeededRandom::fromKey('FIN:'.$inst->exchange->code.'.'.$inst->symbol);
            $close = $inst->latestQuote ? (float) $inst->latestQuote->close : (float) ($inst->metadata['demo_base_price'] ?? 100);
            $isFin = $inst->sector === 'Financials';
            $isNegative = strtoupper($inst->symbol) === 'GOTO';
            $stmtCcy = strtoupper($inst->symbol) === 'ADRO' ? 'USD' : $inst->currency;
            $fxToStmt = match ([$inst->currency, $stmtCcy]) {
                ['IDR', 'USD'] => 1.0 / 15850.0,
                default => 1.0,
            };

            // Target realistic market cap scale per currency
            $targetMcapInst = match ($inst->currency) {
                'IDR' => (18e12 + $rng->nextFloat() * 320e12),
                'USD' => (45e9 + $rng->nextFloat() * 1400e9),
                'SGD' => (12e9 + $rng->nextFloat() * 85e9),
                'CNY' => (80e9 + $rng->nextFloat() * 900e9),
                'HKD' => (90e9 + $rng->nextFloat() * 1200e9),
                default => 50e9,
            };

            $shares = max(1000000.0, round($targetMcapInst / max(0.5, $close), 0));
            $mcapStmt = ($close * $shares) * $fxToStmt;

            $roe25 = $isNegative ? -0.065 : (0.09 + $rng->nextFloat() * 0.17);
            $pbvTarget = $isNegative ? 1.4 : (1.1 + $rng->nextFloat() * 2.8);
            $equity25 = max(1e6, $mcapStmt / $pbvTarget);
            $netIncome25 = $equity25 * $roe25;
            $netMargin25 = $isNegative ? -0.14 : (0.08 + $rng->nextFloat() * 0.18);
            $revenue25 = abs($netIncome25) / abs($netMargin25);
            $revGrowth = $isNegative ? 0.08 : (0.04 + $rng->nextFloat() * 0.18);
            $revenue24 = $revenue25 / (1.0 + $revGrowth);
            $netIncome24 = $isNegative ? ($netIncome25 * 1.15) : ($netIncome25 / (1.0 + ($revGrowth * 1.1)));
            $equity24 = $equity25 / 1.08;

            foreach ([2024 => [$revenue24, $netIncome24, $equity24], 2025 => [$revenue25, $netIncome25, $equity25]] as $fy => [$rev, $ni, $eq]) {
                $liab = $isFin ? ($eq * (5.2 + $rng->nextFloat() * 3.5)) : ($eq * (0.45 + $rng->nextFloat() * 0.75));
                $debt = $isFin ? null : ($liab * 0.55);
                $assets = $eq + $liab;
                $currLiab = $isFin ? null : ($liab * 0.45);
                $currAssets = $isFin ? null : ($currLiab * (1.25 + $rng->nextFloat() * 1.1));
                $ocf = $isNegative ? ($ni * 0.6) : ($ni * (1.05 + $rng->nextFloat() * 0.25));
                $capex = $isFin ? null : ($rev * (0.04 + $rng->nextFloat() * 0.05));
                $fcf = $isFin ? null : ($ocf - ($capex ?? 0.0));
                $divsPaid = (! $isNegative && $ni > 0) ? ($ni * (0.25 + $rng->nextFloat() * 0.30)) : '0';

                $stmtRows[] = [
                    'instrument_id' => $inst->id,
                    'period_type' => 'FY',
                    'fiscal_year' => $fy,
                    'period_end_date' => "{$fy}-12-31",
                    'published_at' => ($fy + 1).'-03-15',
                    'currency' => $stmtCcy,
                    'is_financial_sector' => $isFin,
                    'revenue' => Dec::round((string) $rev, 4),
                    'gross_profit' => $isFin ? null : Dec::round((string) ($rev * 0.42), 4),
                    'operating_income' => Dec::round((string) ($ni * 1.28), 4),
                    'net_income' => Dec::round((string) $ni, 4),
                    'ebitda' => $isFin ? null : Dec::round((string) ($ni * 1.45), 4),
                    'extraordinary_items' => '0.0000',
                    'total_assets' => Dec::round((string) $assets, 4),
                    'current_assets' => $currAssets !== null ? Dec::round((string) $currAssets, 4) : null,
                    'cash_and_equivalents' => Dec::round((string) ($assets * 0.11), 4),
                    'total_liabilities' => Dec::round((string) $liab, 4),
                    'current_liabilities' => $currLiab !== null ? Dec::round((string) $currLiab, 4) : null,
                    'total_debt' => $debt !== null ? Dec::round((string) $debt, 4) : null,
                    'total_equity' => Dec::round((string) $eq, 4),
                    'operating_cash_flow' => Dec::round((string) $ocf, 4),
                    'capex' => $capex !== null ? Dec::round((string) $capex, 4) : null,
                    'free_cash_flow' => $fcf !== null ? Dec::round((string) $fcf, 4) : null,
                    'dividends_paid' => Dec::round((string) $divsPaid, 4),
                    'shares_outstanding' => Dec::round((string) $shares, 4),
                    'data_source_id' => $source->id,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! $isNegative) {
                $dps = max(0.01, ($close * (0.015 + $rng->nextFloat() * 0.045)));
                $exDate = $today->subDays(45 + (int) ($rng->nextFloat() * 140));
                while ($exDate->isWeekend()) {
                    $exDate = $exDate->subDay();
                }

                $actionRows[] = [
                    'instrument_id' => $inst->id,
                    'action_type' => 'cash_dividend',
                    'ex_date' => $exDate->toDateString(),
                    'record_date' => $exDate->addDay()->toDateString(),
                    'payment_date' => $exDate->addDays(14)->toDateString(),
                    'declared_currency' => $inst->currency,
                    'cash_amount' => Dec::round(number_format($dps, 6, '.', ''), 6),
                    'ratio_numerator' => null,
                    'ratio_denominator' => null,
                    'notes' => 'Demo FY final cash dividend',
                    'data_source_id' => $source->id,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (strtoupper($inst->symbol) === 'NVDA') {
                $splitDate = $today->subDays(180);
                while ($splitDate->isWeekend()) {
                    $splitDate = $splitDate->subDay();
                }
                $actionRows[] = [
                    'instrument_id' => $inst->id,
                    'action_type' => 'stock_split',
                    'ex_date' => $splitDate->toDateString(),
                    'record_date' => null,
                    'payment_date' => null,
                    'declared_currency' => $inst->currency,
                    'cash_amount' => null,
                    'ratio_numerator' => '10.000000',
                    'ratio_denominator' => '1.000000',
                    'notes' => 'Demo 10-for-1 stock split marker',
                    'data_source_id' => $source->id,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($stmtRows, 100) as $chunk) {
            FinancialStatement::query()->upsert(
                $chunk,
                ['instrument_id', 'period_type', 'fiscal_year'],
                [
                    'period_end_date', 'published_at', 'currency', 'is_financial_sector',
                    'revenue', 'gross_profit', 'operating_income', 'net_income', 'ebitda',
                    'extraordinary_items', 'total_assets', 'current_assets', 'cash_and_equivalents',
                    'total_liabilities', 'current_liabilities', 'total_debt', 'total_equity',
                    'operating_cash_flow', 'capex', 'free_cash_flow', 'dividends_paid',
                    'shares_outstanding', 'data_source_id', 'is_demo', 'updated_at',
                ]
            );
        }

        if (! empty($actionRows)) {
            foreach (array_chunk($actionRows, 100) as $chunk) {
                CorporateAction::query()->upsert(
                    $chunk,
                    ['instrument_id', 'action_type', 'ex_date'],
                    ['record_date', 'payment_date', 'declared_currency', 'cash_amount', 'ratio_numerator', 'ratio_denominator', 'notes', 'data_source_id', 'is_demo', 'updated_at']
                );
            }
        }

        // 2b. Seed demo regulatory status edge case (GOTO special monitoring) & upcoming demo calendar events
        $upcomingMap = [
            'GOTO' => null,
            'BBCA' => ['days' => 10, 'cash' => '240.000000', 'notes' => 'Demo agenda dividen final mendatang'],
            'MSFT' => ['days' => 18, 'cash' => '0.830000', 'notes' => 'Demo upcoming quarterly cash dividend'],
            'DBS' => ['days' => 24, 'cash' => '0.540000', 'notes' => 'Demo upcoming interim cash dividend'],
        ];
        $upcomingRows = [];
        foreach ($instruments as $inst) {
            $sym = strtoupper($inst->symbol);
            if ($sym === 'GOTO') {
                InstrumentRegulatoryStatus::query()->updateOrCreate(
                    ['instrument_id' => $inst->id],
                    [
                        'is_suspended' => false,
                        'has_exchange_warning' => true,
                        'has_special_monitoring' => true,
                        'free_float_pct' => '8.50',
                        'top_holder_pct' => '74.20',
                        'status_notes' => 'Demo: Pemantauan khusus volatilitas & rugi bersih',
                        'source_note' => 'Demo Simulated Status',
                        'is_demo' => true,
                        'updated_at' => $now,
                    ]
                );
            } elseif (isset($upcomingMap[$sym]) && $upcomingMap[$sym] !== null) {
                $cfg = $upcomingMap[$sym];
                $exDate = $today->addDays($cfg['days']);
                while ($exDate->isWeekend()) {
                    $exDate = $exDate->addDay();
                }
                $upcomingRows[] = [
                    'instrument_id' => $inst->id,
                    'action_type' => 'cash_dividend',
                    'ex_date' => $exDate->toDateString(),
                    'record_date' => $exDate->addDay()->toDateString(),
                    'payment_date' => $exDate->addDays(12)->toDateString(),
                    'declared_currency' => $inst->currency,
                    'cash_amount' => $cfg['cash'],
                    'ratio_numerator' => null,
                    'ratio_denominator' => null,
                    'notes' => $cfg['notes'],
                    'data_source_id' => $source->id,
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (! empty($upcomingRows)) {
            CorporateAction::query()->upsert(
                $upcomingRows,
                ['instrument_id', 'action_type', 'ex_date'],
                ['record_date', 'payment_date', 'declared_currency', 'cash_amount', 'notes', 'data_source_id', 'is_demo', 'updated_at']
            );
        }

        // 3. Run full analytics refresh (includes Stock Safety & Trading Signals)
        $refreshIntelligence->execute();

        // 4. Backfill 8 weekly historical score points around latest computed score
        $scores = InstrumentScore::query()->get();
        $historyRows = [];
        foreach ($scores as $score) {
            $rng = SeededRandom::fromKey('SCHIST:'.$score->instrument_id);
            $base = (float) $score->overall_score;
            for ($w = 8; $w >= 1; $w--) {
                $d = CarbonImmutable::parse($score->as_of_date->toDateString(), 'UTC')->subWeeks($w)->toDateString();
                $jitter = ($rng->nextFloat() - 0.5) * 8.0;
                $val = max(5.0, min(95.0, $base + $jitter));
                $historyRows[] = [
                    'instrument_id' => $score->instrument_id,
                    'score_date' => $d,
                    'overall_score' => Dec::round(number_format($val, 4, '.', ''), 2),
                    'components' => json_encode([
                        'fundamentals' => $score->fundamentals_score,
                        'valuation' => $score->valuation_score,
                        'technical' => $score->technical_score,
                        'risk' => $score->risk_score,
                        'catalysts' => $score->catalysts_score,
                    ]),
                    'calculation_version' => $score->calculation_version,
                    'created_at' => $now,
                ];
            }
        }

        foreach (array_chunk($historyRows, 250) as $chunk) {
            InstrumentScoreHistory::query()->upsert(
                $chunk,
                ['instrument_id', 'score_date'],
                ['overall_score', 'components', 'calculation_version']
            );
        }

        // 5. Backfill 6 weekly historical Stock Safety points around latest assessment
        $assessments = StockSafetyAssessment::query()->whereNotNull('risk_score')->get();
        $safetyHistRows = [];
        foreach ($assessments as $assess) {
            $rng = SeededRandom::fromKey('SAFHIST:'.$assess->instrument_id);
            $base = (float) $assess->risk_score;
            for ($w = 6; $w >= 1; $w--) {
                $d = CarbonImmutable::parse($assess->as_of_date->toDateString(), 'UTC')->subWeeks($w)->toDateString();
                $jitter = ($rng->nextFloat() - 0.5) * 10.0;
                $val = max(2.0, min(98.0, $base + $jitter));
                $cat = match (true) {
                    $val >= 70.0 => 'high',
                    $val >= 50.0 => 'elevated',
                    $val >= 25.0 => 'moderate',
                    default => 'low',
                };
                $safetyHistRows[] = [
                    'instrument_id' => $assess->instrument_id,
                    'assessment_date' => $d,
                    'risk_score' => Dec::round(number_format($val, 4, '.', ''), 2),
                    'risk_category' => $cat,
                    'safeguard_triggered' => $assess->safeguard_triggered && $val >= 65.0,
                    'triggered_count' => is_array($assess->triggered_rules) ? count($assess->triggered_rules) : 0,
                    'created_at' => $now,
                ];
            }
        }

        foreach (array_chunk($safetyHistRows, 250) as $chunk) {
            StockSafetyHistory::query()->upsert(
                $chunk,
                ['instrument_id', 'assessment_date'],
                ['risk_score', 'risk_category', 'safeguard_triggered', 'triggered_count']
            );
        }
    }
}
