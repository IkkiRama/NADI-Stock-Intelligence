<?php

namespace App\Domain\TradingSignals\Services;

use App\Support\Math\Dec;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact decimal Position Sizing Calculator supporting board lots, brokerage fees,
 * slippage assumptions, and capital constraints.
 */
final class PositionSizingCalculator
{
    /**
     * @return array<string, mixed>
     */
    public function calculate(
        string $capital,
        string $maxRiskPct,
        string $entryPrice,
        string $stopLoss,
        ?string $targetPrice = null,
        int $lotSize = 1,
        string $buyFeePct = '0',
        string $sellFeePct = '0',
        string $slippagePct = '0',
    ): array {
        if (Dec::cmp($capital, '0') <= 0) {
            throw new InvalidArgumentException('Capital must be greater than zero.');
        }
        if (Dec::cmp($maxRiskPct, '0') <= 0 || Dec::cmp($maxRiskPct, '100') > 0) {
            throw new InvalidArgumentException('Max risk percentage must be between 0 and 100.');
        }
        if (Dec::cmp($entryPrice, '0') <= 0 || Dec::cmp($stopLoss, '0') <= 0) {
            throw new InvalidArgumentException('Entry and stop-loss prices must be positive.');
        }
        if (Dec::cmp($entryPrice, $stopLoss) <= 0) {
            throw new InvalidArgumentException('Entry price must be strictly greater than stop-loss price.');
        }
        if ($lotSize < 1) {
            throw new InvalidArgumentException('Lot size must be at least 1.');
        }
        if (Dec::cmp($buyFeePct, '0') < 0 || Dec::cmp($sellFeePct, '0') < 0 || Dec::cmp($slippagePct, '0') < 0) {
            throw new InvalidArgumentException('Fee and slippage percentages cannot be negative.');
        }

        $effectiveEntry = Dec::mul($entryPrice, Dec::add('1', $slippagePct, 8), 6);
        $effectiveStop = Dec::mul($stopLoss, Dec::sub('1', $slippagePct, 8), 6);

        $costPerShare = Dec::mul($effectiveEntry, Dec::add('1', $buyFeePct, 8), 6);
        $stopProceedsPerShare = Dec::mul($effectiveStop, Dec::sub('1', $sellFeePct, 8), 6);
        $netRiskPerShare = Dec::sub($costPerShare, $stopProceedsPerShare, 6);

        if (Dec::cmp($netRiskPerShare, '0') <= 0) {
            throw new InvalidArgumentException('Effective stop loss must be below effective entry cost.');
        }

        $riskFraction = Dec::div($maxRiskPct, '100', 8);
        $maxCapitalAtRisk = Dec::mul($capital, $riskFraction ?? '0', 4);

        $riskBasedShares = Dec::div($maxCapitalAtRisk, $netRiskPerShare, 8) ?? '0';
        $affordableShares = Dec::div($capital, $costPerShare, 8) ?? '0';

        $riskLots = (int) (string) Dec::of($riskBasedShares)->dividedBy((string) $lotSize, 0, RoundingMode::DOWN);
        $affordableLots = (int) (string) Dec::of($affordableShares)->dividedBy((string) $lotSize, 0, RoundingMode::DOWN);

        $lots = max(0, min($riskLots, $affordableLots));
        $cappedByCapital = $affordableLots < $riskLots;
        $shares = $lots * $lotSize;

        $grossValue = Dec::mul((string) $shares, $effectiveEntry, 4);
        $buyFee = Dec::mul($grossValue, $buyFeePct, 4);
        $totalCapitalRequired = Dec::add($grossValue, $buyFee, 4);

        $stopGross = Dec::mul((string) $shares, $effectiveStop, 4);
        $sellFeeAtStop = Dec::mul($stopGross, $sellFeePct, 4);
        $actualCapitalAtRisk = Dec::mul((string) $shares, $netRiskPerShare, 4);
        $actualRiskPct = Dec::mul(Dec::div($actualCapitalAtRisk, $capital, 8) ?? '0', '100', 4);

        $netRr = null;
        $expectedNetProfit = null;
        if ($targetPrice !== null && Dec::cmp($targetPrice, $entryPrice) > 0) {
            $effectiveTarget = Dec::mul($targetPrice, Dec::sub('1', $slippagePct, 8), 6);
            $netTargetProceeds = Dec::mul($effectiveTarget, Dec::sub('1', $sellFeePct, 8), 6);
            $netGainPerShare = Dec::sub($netTargetProceeds, $costPerShare, 6);
            if (Dec::cmp($netGainPerShare, '0') > 0) {
                $netRr = Dec::div($netGainPerShare, $netRiskPerShare, 4);
            }
            $expectedNetProfit = Dec::mul((string) $shares, $netGainPerShare, 4);
        }

        return [
            'lots' => $lots,
            'lot_size' => $lotSize,
            'shares' => $shares,
            'effective_entry' => $effectiveEntry,
            'effective_stop' => $effectiveStop,
            'gross_position_value' => $grossValue,
            'buy_fee' => $buyFee,
            'sell_fee_at_stop' => $sellFeeAtStop,
            'total_capital_required' => $totalCapitalRequired,
            'max_capital_at_risk' => $maxCapitalAtRisk,
            'actual_capital_at_risk' => $actualCapitalAtRisk,
            'actual_risk_pct' => $actualRiskPct,
            'capped_by_capital' => $cappedByCapital,
            'net_rr' => $netRr,
            'expected_net_profit' => $expectedNetProfit,
        ];
    }
}
