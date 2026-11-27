<?php

namespace App\Domain\Notifications\Services;

use App\Models\AlertEvent;
use App\Models\StockSafetyAssessment;
use App\Models\TradingSignal;
use App\Models\UserAlert;
use App\Support\Math\Dec;

final class AlertEvaluator
{
    /**
     * Evaluate all active alerts across instruments and emit idempotent daily AlertEvents.
     */
    public function evaluateAll(): int
    {
        $alerts = UserAlert::query()
            ->with(['instrument.exchange:id,code', 'instrument.latestQuote', 'instrument.safetyAssessment'])
            ->where('is_active', true)
            ->get();

        if ($alerts->isEmpty()) {
            return 0;
        }

        $instIds = $alerts->pluck('instrument_id')->unique()->values()->all();
        $latestSignals = TradingSignal::query()
            ->whereIn('instrument_id', $instIds)
            ->where('is_latest', true)
            ->get()
            ->groupBy('instrument_id');

        $createdCount = 0;

        foreach ($alerts as $alert) {
            $inst = $alert->instrument;
            if (! $inst || ! $inst->is_active) {
                continue;
            }

            $quote = $inst->latestQuote;
            $safety = $inst->safetyAssessment;
            $horizon = $alert->horizon ?: 'swing';
            $signal = ($latestSignals->get($inst->id) ?? collect())->firstWhere('horizon', $horizon);

            $triggered = false;
            $triggeredDate = now('UTC')->toDateString();
            $message = '';
            $context = [];

            if ($alert->alert_type === 'price_above' && $quote && $alert->threshold_value !== null) {
                if (Dec::cmp((string) $quote->close, (string) $alert->threshold_value) >= 0) {
                    $triggered = true;
                    $triggeredDate = $quote->trade_date->toDateString();
                    $message = "{$inst->symbol} menembus batas atas {$alert->threshold_value} {$inst->currency} (close: {$quote->close}).";
                    $context = ['close' => (string) $quote->close, 'threshold' => (string) $alert->threshold_value];
                }
            } elseif ($alert->alert_type === 'price_below' && $quote && $alert->threshold_value !== null) {
                if (Dec::cmp((string) $quote->close, (string) $alert->threshold_value) <= 0) {
                    $triggered = true;
                    $triggeredDate = $quote->trade_date->toDateString();
                    $message = "{$inst->symbol} turun melewati batas {$alert->threshold_value} {$inst->currency} (close: {$quote->close}).";
                    $context = ['close' => (string) $quote->close, 'threshold' => (string) $alert->threshold_value];
                }
            } elseif ($alert->alert_type === 'safety_risk_above' && $safety instanceof StockSafetyAssessment && $safety->risk_score !== null && $alert->threshold_value !== null) {
                if (Dec::cmp((string) $safety->risk_score, (string) $alert->threshold_value) >= 0) {
                    $triggered = true;
                    $triggeredDate = $safety->as_of_date->toDateString();
                    $message = "Skor risiko Stock Safety {$inst->symbol} mencapai {$safety->risk_score} ({$safety->risk_category}).";
                    $context = ['risk_score' => (string) $safety->risk_score, 'risk_category' => $safety->risk_category];
                }
            } elseif ($alert->alert_type === 'signal_actionable' && $signal instanceof TradingSignal) {
                if ($signal->stance === 'actionable_setup' && ! $signal->safeguard_suppressed) {
                    $triggered = true;
                    $triggeredDate = $signal->signal_date->toDateString();
                    $message = "Setup {$horizon} terkonfirmasi untuk {$inst->symbol} (Net R:R {$signal->net_rr}x).";
                    $context = ['horizon' => $horizon, 'net_rr' => (string) $signal->net_rr, 'entry_low' => (string) $signal->entry_low];
                }
            }

            if (! $triggered) {
                continue;
            }

            $exists = AlertEvent::query()
                ->where('user_alert_id', $alert->id)
                ->whereDate('triggered_date', $triggeredDate)
                ->exists();

            if (! $exists) {
                AlertEvent::query()->create([
                    'user_alert_id' => $alert->id,
                    'triggered_date' => $triggeredDate,
                    'user_id' => $alert->user_id,
                    'instrument_id' => $inst->id,
                    'message' => mb_substr($message, 0, 255),
                    'context' => $context,
                    'created_at' => now('UTC'),
                ]);
                $createdCount++;
            }

            $alert->forceFill(['last_triggered_at' => now('UTC')])->save();
        }

        return $createdCount;
    }
}
