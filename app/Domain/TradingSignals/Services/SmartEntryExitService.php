<?php

namespace App\Domain\TradingSignals\Services;

use App\Domain\Instruments\Support\TickSize;
use App\Models\Instrument;
use App\Support\Math\Dec;

/**
 * NADI Smart Entry & Exit Rule Engine.
 *
 * Generates horizon-specific staged entry zones, confirmation trigger, stop-loss,
 * profit targets, and fee/slippage-adjusted R:R aligned to exchange tick sizes.
 * Never forces a BUY setup when trend/R:R fail or when Stock Safety safeguards trigger.
 */
final class SmartEntryExitService
{
    public const HORIZONS = ['short_term', 'swing', 'long_term'];

    public const VERSION = 'v1.0';

    /**
     * @param  list<array{date: string, open: string, high: string, low: string, close: string, volume: int}>  $bars
     * @param  array<string, mixed>|null  $fundamentals
     * @param  array<string, mixed>|null  $technicals
     * @param  array<string, mixed>|null  $score
     * @param  array<string, mixed>  $safety
     * @return array<string, array<string, mixed>>
     */
    public function evaluateAllHorizons(
        Instrument $instrument,
        string $exchangeCode,
        array $bars,
        ?array $fundamentals,
        ?array $technicals,
        ?array $score,
        array $safety,
        string $asOfDate,
    ): array {
        $plans = [];
        foreach (self::HORIZONS as $horizon) {
            $plans[$horizon] = $this->evaluateHorizon(
                $instrument,
                $exchangeCode,
                $horizon,
                $bars,
                $fundamentals,
                $technicals,
                $score,
                $safety,
                $asOfDate,
            );
        }

        return $plans;
    }

    /**
     * @param  list<array{date: string, open: string, high: string, low: string, close: string, volume: int}>  $bars
     * @param  array<string, mixed>|null  $fundamentals
     * @param  array<string, mixed>|null  $technicals
     * @param  array<string, mixed>|null  $score
     * @param  array<string, mixed>  $safety
     * @return array<string, mixed>
     */
    public function evaluateHorizon(
        Instrument $instrument,
        string $exchangeCode,
        string $horizon,
        array $bars,
        ?array $fundamentals,
        ?array $technicals,
        ?array $score,
        array $safety,
        string $asOfDate,
    ): array {
        $isDemo = (bool) ($instrument->latestQuote?->is_demo ?? ($safety['is_demo'] ?? false));
        $barCount = count($bars);

        if ($barCount < 50 || $technicals === null) {
            return $this->emptyPlan($instrument->id, $horizon, $asOfDate, 'insufficient_data', [
                ['status' => 'info', 'text' => 'Riwayat harga harian belum mencapai minimum 50 sesi perdagangan.'],
            ], null, null, false, $isDemo);
        }

        $lookback = match ($horizon) {
            'short_term' => 20,
            'long_term' => min(90, $barCount),
            default => 40,
        };

        $slice = array_slice($bars, -$lookback);
        $swingLow = min(array_map(fn ($b) => (float) $b['low'], $slice));
        $swingHigh = max(array_map(fn ($b) => (float) $b['high'], $slice));

        $supportLevel = TickSize::roundToTick($exchangeCode, number_format($swingLow, 6, '.', ''));
        $resistanceLevel = TickSize::roundToTick($exchangeCode, number_format($swingHigh, 6, '.', ''));

        // Stock Safety Safeguard Override — never emit actionable BUY when safeguard triggers
        if ((bool) ($safety['safeguard_triggered'] ?? false)) {
            $ruleNames = array_map(fn ($r) => $r['title'] ?? $r['code'], $safety['triggered_rules'] ?? []);
            $ruleSummary = ! empty($ruleNames) ? implode(', ', array_slice($ruleNames, 0, 3)) : 'Skor risiko tinggi';

            return $this->emptyPlan(
                $instrument->id,
                $horizon,
                $asOfDate,
                'suppressed_by_safety',
                [
                    ['status' => 'warning', 'text' => "Sinyal entry ditahan otomatis oleh NADI Stock Safety ({$ruleSummary})."],
                    ['status' => 'info', 'text' => 'Gunakan level support/resistance hanya sebagai referensi manajemen risiko.'],
                ],
                $supportLevel,
                $resistanceLevel,
                true,
                $isDemo,
            );
        }

        $latest = $bars[$barCount - 1];
        $close = (float) $latest['close'];
        $sma20 = isset($technicals['sma_20']) && $technicals['sma_20'] !== null ? (float) $technicals['sma_20'] : null;
        $sma50 = isset($technicals['sma_50']) && $technicals['sma_50'] !== null ? (float) $technicals['sma_50'] : null;
        $sma200 = isset($technicals['sma_200']) && $technicals['sma_200'] !== null ? (float) $technicals['sma_200'] : null;
        $ema20 = isset($technicals['ema_20']) && $technicals['ema_20'] !== null ? (float) $technicals['ema_20'] : null;
        $rsi14 = isset($technicals['rsi_14']) && $technicals['rsi_14'] !== null ? (float) $technicals['rsi_14'] : null;
        $atr14 = isset($technicals['atr_14']) && $technicals['atr_14'] !== null ? (float) $technicals['atr_14'] : ($close * 0.025);
        $overallScore = $score !== null && isset($score['overall_score']) ? (float) $score['overall_score'] : 50.0;
        $eps = $fundamentals !== null && isset($fundamentals['eps']) && $fundamentals['eps'] !== null ? (float) $fundamentals['eps'] : null;

        $rationale = [];
        $setupValid = true;

        if ($horizon === 'short_term') {
            $trendOk = $sma20 !== null && $close >= ($sma20 * 0.985) && ($sma50 === null || $close >= ($sma50 * 0.98));
            $rsiOk = $rsi14 !== null && $rsi14 >= 42.0 && $rsi14 <= 72.0;
            $scoreOk = $overallScore >= 48.0;

            $rationale[] = ['status' => $trendOk ? 'pass' : 'fail', 'text' => $trendOk ? 'Harga bertahan di dekat/atas SMA 20 jangka pendek.' : 'Harga berada di bawah tren rata-rata SMA 20.'];
            $rationale[] = ['status' => $rsiOk ? 'pass' : 'fail', 'text' => $rsiOk ? 'RSI(14) berada pada zona momentum sehat (42–72).' : 'RSI(14) di luar rentang momentum sehat atau overbought.'];
            $rationale[] = ['status' => $scoreOk ? 'pass' : 'fail', 'text' => $scoreOk ? 'Skor kuantitatif memenuhi ambang minimum (>= 48).' : 'Skor kuantitatif di bawah ambang minimum (< 48).'];

            $setupValid = $trendOk && $rsiOk && $scoreOk;
        } elseif ($horizon === 'long_term') {
            $trendOk = $sma200 !== null ? $close >= ($sma200 * 0.98) : ($sma50 !== null && $close >= ($sma50 * 0.98));
            $fundOk = $eps === null || $eps > 0.0;
            $scoreOk = $overallScore >= 58.0;

            $rationale[] = ['status' => $trendOk ? 'pass' : 'fail', 'text' => $trendOk ? 'Tren jangka panjang di atas penopang SMA utama.' : 'Harga berada di bawah tren utama jangka panjang.'];
            $rationale[] = ['status' => $fundOk ? 'pass' : 'fail', 'text' => $fundOk ? 'Laba per saham (EPS) positif.' : 'EPS negatif/nol — tidak memenuhi kriteria akumulasi jangka panjang.'];
            $rationale[] = ['status' => $scoreOk ? 'pass' : 'fail', 'text' => $scoreOk ? 'Skor kualitas NADI >= 58.' : 'Skor kualitas NADI di bawah 58 untuk posisi jangka panjang.'];

            $setupValid = $trendOk && $fundOk && $scoreOk;
        } else {
            // swing (default)
            $trendOk = $sma50 !== null && $close >= ($sma50 * 0.99) && ($sma200 === null || $sma50 >= ($sma200 * 0.97));
            $rsiOk = $rsi14 !== null && $rsi14 >= 40.0 && $rsi14 <= 70.0;
            $scoreOk = $overallScore >= 52.0;

            $rationale[] = ['status' => $trendOk ? 'pass' : 'fail', 'text' => $trendOk ? 'Struktur swing di atas SMA 50 terjaga.' : 'Harga di bawah struktur SMA 50 sehingga risiko breakdown meningkat.'];
            $rationale[] = ['status' => $rsiOk ? 'pass' : 'fail', 'text' => $rsiOk ? 'RSI(14) mendukung swing terukur (40–70).' : 'Momentum RSI(14) belum kondusif untuk swing entry.'];
            $rationale[] = ['status' => $scoreOk ? 'pass' : 'fail', 'text' => $scoreOk ? 'Skor NADI memenuhi syarat (>= 52).' : 'Skor NADI masih di bawah ambang swing (>= 52).'];

            $setupValid = $trendOk && $rsiOk && $scoreOk;
        }

        if (! $setupValid) {
            return $this->emptyPlan(
                $instrument->id,
                $horizon,
                $asOfDate,
                'no_setup',
                $rationale,
                $supportLevel,
                $resistanceLevel,
                false,
                $isDemo,
            );
        }

        // Derive ATR & Support-anchored entry, stop-loss, and targets
        $anchor = $ema20 !== null ? min($close, ($close * 0.65) + ($ema20 * 0.35)) : $close;
        $rawEntryLow = match ($horizon) {
            'short_term' => max($swingLow * 1.005, $anchor - ($atr14 * 0.65)),
            'long_term' => max($swingLow * 1.01, ($sma50 ?? $anchor) - ($atr14 * 0.8)),
            default => max($swingLow * 1.005, $anchor - ($atr14 * 0.9)),
        };
        $rawEntryHigh = match ($horizon) {
            'short_term' => $close * 1.002,
            'long_term' => $close * 1.01,
            default => max($rawEntryLow * 1.01, $close * 1.005),
        };

        $entryLow = TickSize::roundToTick($exchangeCode, number_format($rawEntryLow, 6, '.', ''));
        $entryHigh = TickSize::roundToTick($exchangeCode, number_format(max($rawEntryLow, $rawEntryHigh), 6, '.', ''));

        $midEntry = Dec::div(Dec::add($entryLow, $entryHigh, 6), '2', 6);
        $entryTick = TickSize::forPrice($exchangeCode, $entryLow);

        $rawStopMult = match ($horizon) {
            'short_term' => 1.5,
            'long_term' => 2.8,
            default => 2.0,
        };
        $rawStop = min((float) $entryLow - (float) $entryTick * 2, (float) $entryLow - ($atr14 * $rawStopMult));
        $rawStop = max((float) $entryTick, $rawStop);
        $stopLoss = TickSize::roundToTick($exchangeCode, number_format($rawStop, 6, '.', ''));

        // Guarantee stopLoss < entryLow by at least 1 tick
        if (Dec::cmp($stopLoss, $entryLow) >= 0) {
            $fallbackStop = Dec::sub($entryLow, $entryTick, 6);
            $stopLoss = Dec::cmp($fallbackStop, '0') > 0 ? TickSize::roundToTick($exchangeCode, $fallbackStop) : $entryTick;
        }

        $stopTick = TickSize::forPrice($exchangeCode, $stopLoss);
        $invalCandidate = Dec::sub($stopLoss, $stopTick, 6);
        $invalidationPrice = Dec::cmp($invalCandidate, '0') > 0 ? TickSize::roundToTick($exchangeCode, $invalCandidate) : $stopLoss;

        $riskPerShare = Dec::sub($midEntry, $stopLoss, 6);
        if (Dec::cmp($riskPerShare, '0') <= 0) {
            return $this->emptyPlan($instrument->id, $horizon, $asOfDate, 'no_setup', $rationale, $supportLevel, $resistanceLevel, false, $isDemo);
        }

        $t1Mult = match ($horizon) {
            'short_term' => '1.8',
            'long_term' => '3.2',
            default => '2.2',
        };
        $t2Mult = match ($horizon) {
            'short_term' => '2.8',
            'long_term' => '4.8',
            default => '3.5',
        };

        $t1ByRisk = Dec::add($midEntry, Dec::mul($riskPerShare, $t1Mult, 6), 6);
        $target1 = TickSize::roundToTick($exchangeCode, $t1ByRisk);
        $target2 = TickSize::roundToTick($exchangeCode, Dec::add($midEntry, Dec::mul($riskPerShare, $t2Mult, 6), 6));
        $confirmationPrice = TickSize::roundToTick($exchangeCode, number_format(max($close, (float) $entryHigh), 6, '.', ''));

        // Gross & Fee/Slippage-Adjusted Net Risk/Reward to Target 1
        $costs = config('nadi.trading_costs.'.strtoupper($exchangeCode)) ?? config('nadi.trading_costs.DEFAULT');
        $buyFee = (string) ($costs['buy_fee_pct'] ?? '0.0015');
        $sellFee = (string) ($costs['sell_fee_pct'] ?? '0.0015');
        $slippage = (string) ($costs['slippage_pct'] ?? '0.0010');

        $effEntry = Dec::mul($midEntry, Dec::add('1', $slippage, 6), 6);
        $effStop = Dec::mul($stopLoss, Dec::sub('1', $slippage, 6), 6);
        $effTarget1 = Dec::mul($target1, Dec::sub('1', $slippage, 6), 6);

        $grossReward = Dec::sub($target1, $midEntry, 6);
        $grossRr = Dec::div($grossReward, $riskPerShare, 4);

        $netGainPerShare = Dec::sub(
            Dec::mul($effTarget1, Dec::sub('1', $sellFee, 6), 6),
            Dec::mul($effEntry, Dec::add('1', $buyFee, 6), 6),
            6
        );
        $netLossPerShare = Dec::sub(
            Dec::mul($effEntry, Dec::add('1', $buyFee, 6), 6),
            Dec::mul($effStop, Dec::sub('1', $sellFee, 6), 6),
            6
        );
        $netRr = ($netLossPerShare !== null && Dec::cmp($netLossPerShare, '0') > 0)
            ? Dec::div($netGainPerShare, $netLossPerShare, 4)
            : null;

        // Reject setups whose net Risk/Reward is < 1.50
        if ($netRr === null || Dec::cmp($netRr, '1.50') < 0) {
            $rationale[] = ['status' => 'fail', 'text' => 'Rasio Risk/Reward bersih (< 1.50x setelah biaya transaksi & slippage) belum memadai.'];

            return $this->emptyPlan($instrument->id, $horizon, $asOfDate, 'no_setup', $rationale, $supportLevel, $resistanceLevel, false, $isDemo);
        }

        $rationale[] = ['status' => 'pass', 'text' => "Net R:R terukur sebesar {$netRr}x setelah asumsi biaya transaksi & slippage."];

        $isActionable = Dec::cmp($netRr, '1.80') >= 0
            && $overallScore >= 55.0
            && ($sma20 === null || $close >= $sma20);

        $stage1Price = $entryHigh;
        $stage2Price = TickSize::roundToTick($exchangeCode, $midEntry);
        $stage3Price = $entryLow;

        $entryStages = [
            ['stage' => 1, 'weight_pct' => 40, 'price' => $stage1Price, 'label' => 'Konfirmasi zona atas'],
            ['stage' => 2, 'weight_pct' => 35, 'price' => $stage2Price, 'label' => 'Pullback tengah zona'],
            ['stage' => 3, 'weight_pct' => 25, 'price' => $stage3Price, 'label' => 'Dekat support terukur'],
        ];

        $trailMult = match ($horizon) {
            'short_term' => '1.50',
            'long_term' => '2.50',
            default => '2.00',
        };

        return [
            'instrument_id' => $instrument->id,
            'horizon' => $horizon,
            'signal_date' => $asOfDate,
            'is_latest' => true,
            'stance' => $isActionable ? 'actionable_setup' : 'watchlist_setup',
            'entry_low' => $entryLow,
            'entry_high' => $entryHigh,
            'confirmation_price' => $confirmationPrice,
            'stop_loss' => $stopLoss,
            'invalidation_price' => $invalidationPrice,
            'target_1' => $target1,
            'target_2' => $target2,
            'trailing_stop_atr_mult' => $trailMult,
            'support_level' => $supportLevel,
            'resistance_level' => $resistanceLevel,
            'gross_rr' => $grossRr,
            'net_rr' => $netRr,
            'entry_stages' => $entryStages,
            'rationale' => $rationale,
            'invalidation_condition' => "Setup batal apabila penutupan harian menembus di bawah {$invalidationPrice} {$instrument->currency}.",
            'safeguard_suppressed' => false,
            'calculation_version' => self::VERSION,
            'is_demo' => $isDemo,
        ];
    }

    /**
     * @param  list<array{status: string, text: string}>  $rationale
     * @return array<string, mixed>
     */
    private function emptyPlan(
        int $instrumentId,
        string $horizon,
        string $asOfDate,
        string $stance,
        array $rationale,
        ?string $supportLevel,
        ?string $resistanceLevel,
        bool $safeguardSuppressed,
        bool $isDemo,
    ): array {
        return [
            'instrument_id' => $instrumentId,
            'horizon' => $horizon,
            'signal_date' => $asOfDate,
            'is_latest' => true,
            'stance' => $stance,
            'entry_low' => null,
            'entry_high' => null,
            'confirmation_price' => null,
            'stop_loss' => null,
            'invalidation_price' => null,
            'target_1' => null,
            'target_2' => null,
            'trailing_stop_atr_mult' => null,
            'support_level' => $supportLevel,
            'resistance_level' => $resistanceLevel,
            'gross_rr' => null,
            'net_rr' => null,
            'entry_stages' => null,
            'rationale' => $rationale,
            'invalidation_condition' => null,
            'safeguard_suppressed' => $safeguardSuppressed,
            'calculation_version' => self::VERSION,
            'is_demo' => $isDemo,
        ];
    }
}
