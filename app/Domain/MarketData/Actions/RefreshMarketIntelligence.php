<?php

namespace App\Domain\MarketData\Actions;

use App\Domain\Analytics\Services\FundamentalCalculator;
use App\Domain\Analytics\Services\PriceReturnService;
use App\Domain\Analytics\Services\StockScoringService;
use App\Domain\Analytics\Services\TechnicalIndicatorService;
use App\Domain\Notifications\Services\AlertEvaluator;
use App\Domain\StockSafety\Services\StockSafetyService;
use App\Domain\TradingSignals\Services\SmartEntryExitService;
use App\Models\DataImportRun;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentFundamentalLatest;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentRegulatoryStatus;
use App\Models\InstrumentScore;
use App\Models\InstrumentScoreHistory;
use App\Models\InstrumentTechnicalLatest;
use App\Models\SafetyConfig;
use App\Models\ScoringConfig;
use App\Models\StockIndex;
use App\Models\StockSafetyAssessment;
use App\Models\StockSafetyHistory;
use App\Models\TradingSignal;
use App\Support\Math\Dec;
use Illuminate\Support\Facades\Cache;

final class RefreshMarketIntelligence
{
    public const COUNTRY_BENCHMARK_MAP = [
        'ID' => 'IHSG',
        'US' => 'SPX',
        'SG' => 'STI',
        'CN' => 'CSI300',
        'HK' => 'HSI',
    ];

    public function __construct(
        private readonly RefreshLatestQuotes $refreshQuotes,
        private readonly FundamentalCalculator $fundamentals,
        private readonly TechnicalIndicatorService $technicals,
        private readonly PriceReturnService $returns,
        private readonly StockScoringService $scoring,
        private readonly StockSafetyService $safetyService,
        private readonly SmartEntryExitService $entryExitService,
        private readonly AlertEvaluator $alertEvaluator,
    ) {}

    /**
     * Recompute latest quotes, fundamentals, technicals, scores, stock safety, trading signals, and alerts.
     *
     * @return array{instruments: int, fundamentals: int, technicals: int, scores: int, safety: int, signals: int, alerts_triggered: int}
     */
    public function execute(?int $userId = null): array
    {
        $startedAt = now('UTC');
        $run = DataImportRun::query()->create([
            'user_id' => $userId,
            'dataset' => 'analytics_refresh',
            'mode' => 'batch',
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $this->refreshQuotes->execute();

        // Preload benchmark 1M returns by country code
        $benchmark1m = [];
        $indices = StockIndex::query()->whereIn('code', array_values(self::COUNTRY_BENCHMARK_MAP))->get()->keyBy('code');
        foreach (self::COUNTRY_BENCHMARK_MAP as $country => $code) {
            $idx = $indices->get($code);
            if (! $idx) {
                continue;
            }
            $closes = IndexValueDaily::query()
                ->where('stock_index_id', $idx->id)
                ->orderBy('trade_date')
                ->pluck('close', 'trade_date')
                ->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => (string) $v])
                ->all();
            $benchmark1m[$country] = $this->returns->periodReturn($closes, '1M');
        }

        $scoringConfig = ScoringConfig::query()->where('is_active', true)->orderByDesc('id')->first();
        $safetyConfig = SafetyConfig::query()->where('is_active', true)->orderByDesc('id')->first();
        $regulatoryById = InstrumentRegulatoryStatus::query()->get()->keyBy('instrument_id');
        $instruments = Instrument::query()->with(['exchange', 'latestQuote'])->where('is_active', true)->get();

        $fundById = [];
        $techById = [];
        $barsById = [];
        $closeById = [];
        $asOfById = [];
        $now = now('UTC');

        foreach ($instruments as $inst) {
            $quote = $inst->latestQuote;
            $close = $quote ? (string) $quote->close : null;
            $asOf = $quote?->trade_date?->toDateString() ?? now('UTC')->toDateString();

            $closeById[$inst->id] = $close;
            $asOfById[$inst->id] = $asOf;

            $fund = $this->fundamentals->calculateForInstrument($inst, $close, $asOf);
            if ($fund !== null) {
                $fundById[$inst->id] = $fund;
            }

            $bars = InstrumentPriceDaily::query()
                ->where('instrument_id', $inst->id)
                ->orderByDesc('trade_date')
                ->limit(260)
                ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume', 'value_traded'])
                ->reverse()
                ->values();

            $barsById[$inst->id] = [];
            if ($bars->isNotEmpty()) {
                $barArray = [];
                $closesByDate = [];
                foreach ($bars as $b) {
                    $d = $b->trade_date->toDateString();
                    $closesByDate[$d] = (string) $b->close;
                    $barArray[] = [
                        'date' => $d,
                        'open' => (string) $b->open,
                        'high' => (string) $b->high,
                        'low' => (string) $b->low,
                        'close' => (string) $b->close,
                        'volume' => (int) $b->volume,
                        'value_traded' => $b->value_traded !== null ? (string) $b->value_traded : null,
                    ];
                }
                $barsById[$inst->id] = $barArray;

                $snap = $this->technicals->latestSnapshot($barArray);
                if ($snap !== null) {
                    $r1w = $this->returns->periodReturn($closesByDate, '1W');
                    $r1m = $this->returns->periodReturn($closesByDate, '1M');
                    $r3m = $this->returns->periodReturn($closesByDate, '3M');
                    $r6m = $this->returns->periodReturn($closesByDate, '6M');
                    $r1y = $this->returns->periodReturn($closesByDate, '1Y');

                    $bench1m = $benchmark1m[$inst->country_code] ?? null;
                    $rel1m = ($r1m !== null && $bench1m !== null) ? Dec::sub($r1m, $bench1m, 8) : null;

                    $techById[$inst->id] = array_merge($snap, [
                        'instrument_id' => $inst->id,
                        'return_1w' => $r1w,
                        'return_1m' => $r1m,
                        'return_3m' => $r3m,
                        'return_6m' => $r6m,
                        'return_1y' => $r1y,
                        'relative_strength_1m' => $rel1m,
                        'calculated_at' => $now,
                    ]);
                }
            }
        }

        // Compute sector medians for valuation/profitability context
        $sectorStats = [];
        foreach ($instruments as $inst) {
            $sec = $inst->sector ?: 'Other';
            $f = $fundById[$inst->id] ?? null;
            if (! $f) {
                continue;
            }
            foreach (['per', 'pbv', 'roe', 'net_margin'] as $field) {
                if ($f[$field] !== null) {
                    $sectorStats[$sec][$field][] = (float) $f[$field];
                }
            }
        }

        $sectorMedians = [];
        foreach ($sectorStats as $sec => $fields) {
            foreach ($fields as $field => $vals) {
                sort($vals);
                $cnt = count($vals);
                $mid = intdiv($cnt, 2);
                $sectorMedians[$sec][$field] = $cnt % 2 === 1
                    ? $vals[$mid]
                    : ($vals[$mid - 1] + $vals[$mid]) / 2.0;
            }
        }

        // Compute scores
        $scoresById = [];
        foreach ($instruments as $inst) {
            $sec = $inst->sector ?: 'Other';
            $scoresById[$inst->id] = $this->scoring->scoreInstrument(
                $inst,
                $closeById[$inst->id] ?? null,
                $fundById[$inst->id] ?? null,
                $techById[$inst->id] ?? null,
                $asOfById[$inst->id],
                $sectorMedians[$sec] ?? [],
                $scoringConfig,
            );
        }

        // Compute sector percentiles
        $sectorScores = [];
        foreach ($instruments as $inst) {
            $sec = $inst->sector ?: 'Other';
            $sectorScores[$sec][$inst->id] = (float) $scoresById[$inst->id]['overall_score'];
        }
        foreach ($sectorScores as $sec => $map) {
            $total = count($map);
            foreach ($map as $id => $val) {
                if ($total <= 1) {
                    $scoresById[$id]['sector_percentile'] = '100.00';

                    continue;
                }
                $belowOrEq = 0;
                foreach ($map as $otherVal) {
                    if ($otherVal <= $val) {
                        $belowOrEq++;
                    }
                }
                $pct = (($belowOrEq - 1) / ($total - 1)) * 100.0;
                $scoresById[$id]['sector_percentile'] = Dec::round(number_format($pct, 4, '.', ''), 2);
            }
        }

        // Upsert all snapshots in bounded chunks
        if (! empty($fundById)) {
            foreach (array_chunk(array_values($fundById), 200) as $chunk) {
                $encoded = array_map(function (array $row) {
                    $row['missing_fields'] = json_encode($row['missing_fields'] ?? []);

                    return $row;
                }, $chunk);

                InstrumentFundamentalLatest::query()->upsert(
                    $encoded,
                    ['instrument_id'],
                    [
                        'as_of_date', 'period_label', 'statement_currency', 'market_cap', 'market_cap_idr',
                        'eps', 'per', 'pbv', 'roe', 'roa', 'der', 'current_ratio', 'net_margin',
                        'revenue_growth_yoy', 'earnings_growth_yoy', 'ocf', 'fcf',
                        'dividend_yield', 'dividend_payout_ratio', 'is_financial_sector',
                        'missing_fields', 'data_source_id', 'is_demo', 'calculated_at',
                    ]
                );
            }
        }

        if (! empty($techById)) {
            foreach (array_chunk(array_values($techById), 200) as $chunk) {
                InstrumentTechnicalLatest::query()->upsert(
                    $chunk,
                    ['instrument_id'],
                    [
                        'trade_date', 'sma_20', 'sma_50', 'sma_200', 'ema_20', 'ema_50',
                        'macd_line', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower',
                        'atr_14', 'rsi_14', 'obv', 'volatility_20d',
                        'return_1w', 'return_1m', 'return_3m', 'return_6m', 'return_1y',
                        'relative_strength_1m', 'calculated_at',
                    ]
                );
            }
        }

        if (! empty($scoresById)) {
            $historyRows = [];
            foreach (array_chunk(array_values($scoresById), 200) as $chunk) {
                $encoded = array_map(function (array $row) {
                    $row['reasons'] = json_encode($row['reasons']);
                    $row['missing_indicators'] = json_encode($row['missing_indicators']);
                    $row['weights_used'] = json_encode($row['weights_used']);

                    return $row;
                }, $chunk);

                InstrumentScore::query()->upsert(
                    $encoded,
                    ['instrument_id'],
                    [
                        'as_of_date', 'overall_score', 'fundamentals_score', 'valuation_score',
                        'technical_score', 'risk_score', 'catalysts_score', 'sector_percentile',
                        'data_coverage', 'reasons', 'missing_indicators', 'weights_used',
                        'calculation_version', 'is_demo', 'calculated_at',
                    ]
                );
            }

            foreach ($scoresById as $id => $row) {
                $historyRows[] = [
                    'instrument_id' => $id,
                    'score_date' => $row['as_of_date'],
                    'overall_score' => $row['overall_score'],
                    'components' => json_encode([
                        'fundamentals' => $row['fundamentals_score'],
                        'valuation' => $row['valuation_score'],
                        'technical' => $row['technical_score'],
                        'risk' => $row['risk_score'],
                        'catalysts' => $row['catalysts_score'],
                    ]),
                    'calculation_version' => $row['calculation_version'],
                    'created_at' => $now,
                ];
            }

            foreach (array_chunk($historyRows, 200) as $chunk) {
                InstrumentScoreHistory::query()->upsert(
                    $chunk,
                    ['instrument_id', 'score_date'],
                    ['overall_score', 'components', 'calculation_version']
                );
            }
        }

        // Compute Stock Safety Assessments & Smart Entry/Exit Signals (short_term, swing, long_term)
        $safetyRows = [];
        $safetyHistoryRows = [];
        $signalRows = [];
        $signalLatestCutoffById = [];

        foreach ($instruments as $inst) {
            $asOf = $asOfById[$inst->id];
            $bars = $barsById[$inst->id] ?? [];
            $fund = $fundById[$inst->id] ?? null;
            $tech = $techById[$inst->id] ?? null;
            $score = $scoresById[$inst->id] ?? null;
            $reg = $regulatoryById->get($inst->id);

            $safety = $this->safetyService->assess($inst, $bars, $fund, $tech, $reg, $asOf, $safetyConfig);
            $safetyRows[] = $safety;
            $safetyHistoryRows[] = [
                'instrument_id' => $inst->id,
                'assessment_date' => $asOf,
                'risk_score' => $safety['risk_score'],
                'risk_category' => $safety['risk_category'],
                'safeguard_triggered' => $safety['safeguard_triggered'],
                'triggered_count' => count($safety['triggered_rules']),
                'created_at' => $now,
            ];

            $exchangeCode = $inst->exchange->code;
            $plans = $this->entryExitService->evaluateAllHorizons($inst, $exchangeCode, $bars, $fund, $tech, $score, $safety, $asOf);
            $signalLatestCutoffById[$inst->id] = $asOf;

            foreach ($plans as $plan) {
                $plan['created_at'] = $now;
                $plan['updated_at'] = $now;
                $signalRows[] = $plan;
            }
        }

        if (! empty($safetyRows)) {
            foreach (array_chunk($safetyRows, 200) as $chunk) {
                $encoded = array_map(function (array $row): array {
                    $row['triggered_rules'] = json_encode($row['triggered_rules']);
                    $row['missing_factors'] = json_encode($row['missing_factors']);

                    return $row;
                }, $chunk);

                StockSafetyAssessment::query()->upsert(
                    $encoded,
                    ['instrument_id'],
                    [
                        'as_of_date', 'risk_score', 'risk_category', 'safeguard_triggered',
                        'triggered_rules', 'data_confidence', 'missing_factors',
                        'calculation_version', 'is_demo', 'calculated_at',
                    ]
                );
            }

            foreach (array_chunk($safetyHistoryRows, 200) as $chunk) {
                StockSafetyHistory::query()->upsert(
                    $chunk,
                    ['instrument_id', 'assessment_date'],
                    ['risk_score', 'risk_category', 'safeguard_triggered', 'triggered_count']
                );
            }
        }

        if (! empty($signalRows)) {
            foreach ($signalLatestCutoffById as $instId => $latestDate) {
                TradingSignal::query()
                    ->where('instrument_id', $instId)
                    ->where('is_latest', true)
                    ->whereDate('signal_date', '<>', $latestDate)
                    ->update(['is_latest' => false]);
            }

            foreach (array_chunk($signalRows, 150) as $chunk) {
                $encoded = array_map(function (array $row): array {
                    $row['entry_stages'] = $row['entry_stages'] !== null ? json_encode($row['entry_stages']) : null;
                    $row['rationale'] = json_encode($row['rationale']);

                    return $row;
                }, $chunk);

                TradingSignal::query()->upsert(
                    $encoded,
                    ['instrument_id', 'horizon', 'signal_date'],
                    [
                        'is_latest', 'stance', 'entry_low', 'entry_high', 'confirmation_price',
                        'stop_loss', 'invalidation_price', 'target_1', 'target_2',
                        'trailing_stop_atr_mult', 'support_level', 'resistance_level',
                        'gross_rr', 'net_rr', 'entry_stages', 'rationale',
                        'invalidation_condition', 'safeguard_suppressed',
                        'calculation_version', 'is_demo', 'updated_at',
                    ]
                );
            }
        }

        $alertsTriggered = $this->alertEvaluator->evaluateAll();

        $currentVersion = (int) Cache::get(RefreshLatestQuotes::CACHE_VERSION_KEY, 1);
        Cache::forever(RefreshLatestQuotes::CACHE_VERSION_KEY, $currentVersion + 1);

        $run->forceFill([
            'status' => 'completed',
            'rows_processed' => $instruments->count(),
            'rows_inserted' => count($scoresById),
            'finished_at' => now('UTC'),
        ])->save();

        return [
            'instruments' => $instruments->count(),
            'fundamentals' => count($fundById),
            'technicals' => count($techById),
            'scores' => count($scoresById),
            'safety' => count($safetyRows),
            'signals' => count($signalRows),
            'alerts_triggered' => $alertsTriggered,
        ];
    }
}
