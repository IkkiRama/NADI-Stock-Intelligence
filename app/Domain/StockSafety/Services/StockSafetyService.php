<?php

namespace App\Domain\StockSafety\Services;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\Instrument;
use App\Models\InstrumentRegulatoryStatus;
use App\Models\SafetyConfig;
use App\Support\Math\Dec;

/**
 * NADI Stock Safety Assessment Engine.
 *
 * Evaluates statistical price/volume/volatility anomalies, liquidity thickness,
 * fundamental divergence, and regulatory flags. Findings are strictly statistical
 * and structural observations and never assert definitive market manipulation.
 */
final class StockSafetyService
{
    public function __construct(
        private readonly FxConversionService $fx,
    ) {}

    /**
     * @param  list<array{date: string, open: string, high: string, low: string, close: string, volume: int, value_traded?: string|null}>  $bars
     * @param  array<string, mixed>|null  $fundamentals
     * @param  array<string, mixed>|null  $technicals
     * @return array<string, mixed>
     */
    public function assess(
        Instrument $instrument,
        array $bars,
        ?array $fundamentals,
        ?array $technicals,
        ?InstrumentRegulatoryStatus $regulatory,
        string $asOfDate,
        ?SafetyConfig $config = null,
    ): array {
        $thresholds = $config ? $config->mergedThresholds() : SafetyConfig::DEFAULT_THRESHOLDS;
        $version = $config?->version ?? 'v1.0';

        $missingFactors = ['broker_concentration']; // Broker EOD flows arrive in Phase 10
        $triggeredRules = [];
        $riskPoints = 0.0;

        if ($fundamentals === null) {
            $missingFactors[] = 'financial_statements';
        }
        if ($regulatory === null) {
            $missingFactors[] = 'regulatory_status';
        } elseif ($regulatory->free_float_pct === null && $regulatory->top_holder_pct === null) {
            $missingFactors[] = 'ownership_free_float';
        }

        // 1. Regulatory & Ownership Flags
        if ($regulatory !== null) {
            if ($regulatory->is_suspended) {
                $riskPoints += 65.0;
                $triggeredRules[] = [
                    'code' => 'suspended',
                    'severity' => 'critical',
                    'points' => 65,
                    'title' => 'Suspensi Perdagangan Bursa',
                    'evidence' => $regulatory->status_notes ?: 'Instrumen tercatat dalam status suspensi perdagangan.',
                ];
            }

            if ($regulatory->has_special_monitoring) {
                $riskPoints += 35.0;
                $triggeredRules[] = [
                    'code' => 'special_monitoring',
                    'severity' => 'high',
                    'points' => 35,
                    'title' => 'Pemantauan Khusus Bursa',
                    'evidence' => $regulatory->status_notes ?: 'Instrumen masuk daftar pemantauan khusus / perhatian khusus.',
                ];
            }

            if ($regulatory->has_exchange_warning) {
                $riskPoints += 20.0;
                $triggeredRules[] = [
                    'code' => 'exchange_warning',
                    'severity' => 'medium',
                    'points' => 20,
                    'title' => 'Catatan Peringatan Bursa',
                    'evidence' => $regulatory->status_notes ?: 'Terdapat catatan volatilitas/pengawasan bursa pada instrumen ini.',
                ];
            }

            if ($regulatory->free_float_pct !== null && (float) $regulatory->free_float_pct < $thresholds['min_free_float_pct']) {
                $ff = number_format((float) $regulatory->free_float_pct, 2, '.', '');
                $riskPoints += 15.0;
                $triggeredRules[] = [
                    'code' => 'low_free_float',
                    'severity' => 'medium',
                    'points' => 15,
                    'title' => 'Free Float Rendah',
                    'evidence' => "Free float tercatat {$ff}% (< {$thresholds['min_free_float_pct']}%), meningkatkan risiko likuiditas tipis.",
                ];
            }

            if ($regulatory->top_holder_pct !== null && (float) $regulatory->top_holder_pct > $thresholds['max_top_holder_pct']) {
                $th = number_format((float) $regulatory->top_holder_pct, 2, '.', '');
                $riskPoints += 12.0;
                $triggeredRules[] = [
                    'code' => 'ownership_concentration',
                    'severity' => 'medium',
                    'points' => 12,
                    'title' => 'Konsentrasi Kepemilikan Tinggi',
                    'evidence' => "Pemegang saham utama menguasai {$th}% (> {$thresholds['max_top_holder_pct']}%).",
                ];
            }
        }

        $barCount = count($bars);
        $isDemo = (bool) ($instrument->latestQuote?->is_demo ?? ($fundamentals['is_demo'] ?? false));

        if ($barCount < 25) {
            $missingFactors[] = 'price_history_25d';
            $regSafeguard = (bool) ($regulatory?->is_suspended || $regulatory?->has_special_monitoring);

            return [
                'instrument_id' => $instrument->id,
                'as_of_date' => $asOfDate,
                'risk_score' => null,
                'risk_category' => 'insufficient_data',
                'safeguard_triggered' => $regSafeguard,
                'triggered_rules' => $triggeredRules,
                'data_confidence' => $regulatory !== null ? '25.00' : '15.00',
                'missing_factors' => array_values(array_unique($missingFactors)),
                'calculation_version' => $version,
                'is_demo' => $isDemo,
                'calculated_at' => now('UTC'),
            ];
        }

        $lastIdx = $barCount - 1;
        $latestBar = $bars[$lastIdx];
        $latestClose = (float) $latestBar['close'];

        // 2. Unusual 5-day price deviation
        $close5Ago = (float) $bars[max(0, $lastIdx - 5)]['close'];
        $ret5d = $close5Ago > 0.0 ? ($latestClose - $close5Ago) / $close5Ago : 0.0;
        if (abs($ret5d) >= $thresholds['surge_5d_pct']) {
            $pctStr = number_format($ret5d * 100.0, 2, '.', '');
            $riskPoints += 22.0;
            $triggeredRules[] = [
                'code' => 'price_surge_5d',
                'severity' => 'high',
                'points' => 22,
                'title' => 'Deviasi Harga 5 Hari Tidak Lazim',
                'evidence' => "Perubahan harga 5 hari sebesar {$pctStr}% melampaui ambang statistik (±".number_format($thresholds['surge_5d_pct'] * 100, 1).'%).',
            ];
        }

        // 3. Unusual 20-day price deviation
        $close20Ago = (float) $bars[max(0, $lastIdx - 20)]['close'];
        $ret20d = $close20Ago > 0.0 ? ($latestClose - $close20Ago) / $close20Ago : 0.0;
        if (abs($ret20d) >= $thresholds['surge_20d_pct']) {
            $pctStr = number_format($ret20d * 100.0, 2, '.', '');
            $riskPoints += 20.0;
            $triggeredRules[] = [
                'code' => 'price_surge_20d',
                'severity' => 'high',
                'points' => 20,
                'title' => 'Lonjakan Harga 20 Hari Ekstrem',
                'evidence' => "Perubahan harga 20 hari sebesar {$pctStr}% melampaui ambang statistik (±".number_format($thresholds['surge_20d_pct'] * 100, 1).'%).',
            ];
        }

        // 4. Volume spike vs prior 20-bar baseline
        $priorSlice = array_slice($bars, max(0, $lastIdx - 24), 20);
        $recentSlice = array_slice($bars, -5);
        $priorAvgVol = $this->avgVolume($priorSlice);
        $maxRecentVol = 0;
        foreach ($recentSlice as $b) {
            $maxRecentVol = max($maxRecentVol, (int) $b['volume']);
        }
        $volMult = $priorAvgVol > 0.0 ? ($maxRecentVol / $priorAvgVol) : 0.0;
        if ($volMult >= $thresholds['volume_spike_mult']) {
            $multStr = number_format($volMult, 2, '.', '');
            $riskPoints += 18.0;
            $triggeredRules[] = [
                'code' => 'volume_spike',
                'severity' => 'medium',
                'points' => 18,
                'title' => 'Lonjakan Volume Perdagangan',
                'evidence' => "Volume puncak 5 hari terakhir mencapai {$multStr}x rata-rata baseline 20 hari sebelumnya.",
            ];
        }

        // 5. High annualized volatility
        $vol20d = isset($technicals['volatility_20d']) && $technicals['volatility_20d'] !== null
            ? (float) $technicals['volatility_20d']
            : $this->computeVolatility20d($bars);
        if ($vol20d >= $thresholds['volatility_high']) {
            $volStr = number_format($vol20d * 100.0, 1, '.', '');
            $riskPoints += 16.0;
            $triggeredRules[] = [
                'code' => 'high_volatility',
                'severity' => 'medium',
                'points' => 16,
                'title' => 'Volatilitas Harga Tinggi',
                'evidence' => "Volatilitas tahunan 20 hari sebesar {$volStr}% (>= ".number_format($thresholds['volatility_high'] * 100, 1).'%).',
            ];
        }

        // 6. Thin liquidity (20D avg traded value converted to IDR)
        $last20 = array_slice($bars, -20);
        $avgValueLocal = $this->avgValueTraded($last20);
        $fxToIdr = $this->fx->rate($instrument->currency, 'IDR', $asOfDate);
        if ($fxToIdr !== null) {
            $avgValueIdr = $avgValueLocal * (float) $fxToIdr;
            if ($avgValueIdr < $thresholds['min_avg_value_idr']) {
                $idrM = number_format($avgValueIdr / 1_000_000.0, 1, '.', '');
                $riskPoints += 18.0;
                $triggeredRules[] = [
                    'code' => 'thin_liquidity',
                    'severity' => 'medium',
                    'points' => 18,
                    'title' => 'Likuiditas Perdagangan Tipis',
                    'evidence' => "Rata-rata nilai transaksi 20 hari ekuivalen IDR {$idrM} jt berada di bawah ambang likuiditas.",
                ];
            }
        } else {
            $missingFactors[] = 'fx_rate_for_liquidity';
        }

        // 7. Overnight price gap anomaly in last 5 bars
        $maxGap = 0.0;
        $startGapIdx = max(1, $lastIdx - 4);
        for ($i = $startGapIdx; $i <= $lastIdx; $i++) {
            $prevC = (float) $bars[$i - 1]['close'];
            $openC = (float) $bars[$i]['open'];
            if ($prevC > 0.0) {
                $gap = abs($openC - $prevC) / $prevC;
                $maxGap = max($maxGap, $gap);
            }
        }
        if ($maxGap >= $thresholds['gap_pct']) {
            $gapStr = number_format($maxGap * 100.0, 2, '.', '');
            $riskPoints += 12.0;
            $triggeredRules[] = [
                'code' => 'price_gap_anomaly',
                'severity' => 'low',
                'points' => 12,
                'title' => 'Gap Pembukaan Signifikan',
                'evidence' => "Gap harga pembukaan dalam 5 hari terakhir mencapai {$gapStr}% (>= ".number_format($thresholds['gap_pct'] * 100, 1).'%).',
            ];
        }

        // 8. Upper-wick exhaustion / high-volume reversal drop in last 5 bars
        $hasReversalSignal = false;
        $reversalEvidence = '';
        $startRevIdx = max(1, $lastIdx - 4);
        for ($i = $startRevIdx; $i <= $lastIdx; $i++) {
            $b = $bars[$i];
            $o = (float) $b['open'];
            $h = (float) $b['high'];
            $l = (float) $b['low'];
            $c = (float) $b['close'];
            $prevC = (float) $bars[$i - 1]['close'];
            $upperWickPct = $l > 0.0 ? ($h - max($o, $c)) / $l : 0.0;
            $dayRet = $prevC > 0.0 ? ($c - $prevC) / $prevC : 0.0;

            if ($upperWickPct >= 0.06 && $priorAvgVol > 0.0 && ((int) $b['volume']) >= ($priorAvgVol * 1.8)) {
                $hasReversalSignal = true;
                $reversalEvidence = 'Terdeteksi ekor atas ekstrem ('.number_format($upperWickPct * 100, 1).'%) disertai lonjakan volume pada 5 sesi terakhir.';
                break;
            }
            if ($dayRet <= -0.08 && $priorAvgVol > 0.0 && ((int) $b['volume']) >= ($priorAvgVol * 2.0)) {
                $hasReversalSignal = true;
                $reversalEvidence = 'Terdeteksi penurunan harian tajam ('.number_format($dayRet * 100, 1).'%) pada volume >= 2x rata-rata baseline.';
                break;
            }
        }
        if ($hasReversalSignal) {
            $riskPoints += 18.0;
            $triggeredRules[] = [
                'code' => 'pump_reversal_pattern',
                'severity' => 'high',
                'points' => 18,
                'title' => 'Pola Reversal Volatil & Tekanan Jual',
                'evidence' => $reversalEvidence,
            ];
        }

        // 9. Fundamental divergence (strong rally alongside negative earnings or extreme valuation)
        if ($fundamentals !== null && $ret20d >= 0.20) {
            $eps = $fundamentals['eps'] !== null ? (float) $fundamentals['eps'] : null;
            $per = $fundamentals['per'] !== null ? (float) $fundamentals['per'] : null;
            if (($eps !== null && $eps <= 0.0) || ($per !== null && $per >= $thresholds['per_divergence_min'])) {
                $riskPoints += 20.0;
                $detail = $eps !== null && $eps <= 0.0
                    ? 'Reli 20H (+'.number_format($ret20d * 100, 1).'%) terjadi saat EPS negatif/nol.'
                    : 'Reli 20H (+'.number_format($ret20d * 100, 1).'%) terjadi pada PER '.number_format((float) $per, 1).'x.';
                $triggeredRules[] = [
                    'code' => 'fundamental_divergence',
                    'severity' => 'high',
                    'points' => 20,
                    'title' => 'Divergensi Harga & Fundamental',
                    'evidence' => $detail,
                ];
            }
        }

        // Baseline volatility contribution (max 15 points) when market behaves normally
        $baselineVolPts = min(15.0, round($vol20d * 28.0, 2));
        $finalScoreNum = min(100.0, max(0.0, $riskPoints + $baselineVolPts));
        $finalScore = Dec::round(number_format($finalScoreNum, 4, '.', ''), 2);

        $riskCategory = match (true) {
            $finalScoreNum >= 70.0 => 'high',
            $finalScoreNum >= 50.0 => 'elevated',
            $finalScoreNum >= 25.0 => 'moderate',
            default => 'low',
        };

        $safeguardTriggered = $finalScoreNum >= $thresholds['safeguard_score']
            || (bool) ($regulatory?->is_suspended ?? false)
            || (bool) ($regulatory?->has_special_monitoring ?? false);

        // Data confidence calculation: 40 price/vol + 25 fundamentals + 20 regulatory + 15 broker flow
        $confidence = 40.0;
        if ($fundamentals !== null) {
            $confidence += 25.0;
        }
        if ($regulatory !== null) {
            $confidence += 20.0;
        }

        return [
            'instrument_id' => $instrument->id,
            'as_of_date' => $asOfDate,
            'risk_score' => $finalScore,
            'risk_category' => $riskCategory,
            'safeguard_triggered' => $safeguardTriggered,
            'triggered_rules' => $triggeredRules,
            'data_confidence' => Dec::round(number_format($confidence, 2, '.', ''), 2),
            'missing_factors' => array_values(array_unique($missingFactors)),
            'calculation_version' => $version,
            'is_demo' => $isDemo,
            'calculated_at' => now('UTC'),
        ];
    }

    /**
     * @param  list<array{volume: int}>  $slice
     */
    private function avgVolume(array $slice): float
    {
        if (empty($slice)) {
            return 0.0;
        }
        $sum = 0;
        foreach ($slice as $b) {
            $sum += (int) $b['volume'];
        }

        return $sum / count($slice);
    }

    /**
     * @param  list<array{close: string, volume: int, value_traded?: string|null}>  $slice
     */
    private function avgValueTraded(array $slice): float
    {
        if (empty($slice)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($slice as $b) {
            $vt = isset($b['value_traded']) && $b['value_traded'] !== null
                ? (float) $b['value_traded']
                : ((float) $b['close'] * (int) $b['volume']);
            $sum += $vt;
        }

        return $sum / count($slice);
    }

    /**
     * @param  list<array{close: string}>  $bars
     */
    private function computeVolatility20d(array $bars): float
    {
        $slice = array_slice($bars, -21);
        $rets = [];
        for ($i = 1, $n = count($slice); $i < $n; $i++) {
            $prev = (float) $slice[$i - 1]['close'];
            $curr = (float) $slice[$i]['close'];
            if ($prev > 0.0) {
                $rets[] = ($curr - $prev) / $prev;
            }
        }
        if (count($rets) < 5) {
            return 0.0;
        }
        $mean = array_sum($rets) / count($rets);
        $sq = 0.0;
        foreach ($rets as $r) {
            $sq += ($r - $mean) ** 2;
        }
        $std = sqrt($sq / (count($rets) - 1));

        return $std * sqrt(252.0);
    }
}
