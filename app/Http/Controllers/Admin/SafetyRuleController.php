<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentRegulatoryStatus;
use App\Models\SafetyConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SafetyRuleController extends Controller
{
    public function edit(): Response
    {
        $config = SafetyConfig::query()->where('is_active', true)->orderByDesc('id')->first();
        if (! $config) {
            $config = SafetyConfig::query()->create([
                'code' => 'default',
                'name' => 'Standar Stock Safety NADI',
                'thresholds' => SafetyConfig::DEFAULT_THRESHOLDS,
                'version' => 'v1.0',
                'is_active' => true,
            ]);
        }

        $instruments = Instrument::query()
            ->with(['exchange:id,code', 'regulatoryStatus', 'safetyAssessment'])
            ->where('is_active', true)
            ->orderBy('exchange_id')
            ->orderBy('symbol')
            ->get()
            ->map(fn (Instrument $inst): array => [
                'id' => $inst->id,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $inst->exchange->code,
                'risk_score' => $inst->safetyAssessment?->risk_score !== null ? (string) $inst->safetyAssessment->risk_score : null,
                'risk_category' => $inst->safetyAssessment?->risk_category ?? 'insufficient_data',
                'safeguard_triggered' => (bool) ($inst->safetyAssessment?->safeguard_triggered ?? false),
                'is_suspended' => (bool) ($inst->regulatoryStatus?->is_suspended ?? false),
                'has_exchange_warning' => (bool) ($inst->regulatoryStatus?->has_exchange_warning ?? false),
                'has_special_monitoring' => (bool) ($inst->regulatoryStatus?->has_special_monitoring ?? false),
                'free_float_pct' => $inst->regulatoryStatus?->free_float_pct !== null ? (string) $inst->regulatoryStatus->free_float_pct : '',
                'top_holder_pct' => $inst->regulatoryStatus?->top_holder_pct !== null ? (string) $inst->regulatoryStatus->top_holder_pct : '',
                'status_notes' => $inst->regulatoryStatus?->status_notes ?? '',
            ])
            ->values()
            ->all();

        return Inertia::render('admin/safety-rules', [
            'config' => [
                'id' => $config->id,
                'code' => $config->code,
                'name' => $config->name,
                'version' => $config->version,
                'thresholds' => $config->mergedThresholds(),
            ],
            'instruments' => $instruments,
        ]);
    }

    public function update(Request $request, AuditLogger $audit, RefreshMarketIntelligence $refresh): RedirectResponse
    {
        $validated = $request->validate([
            'safeguard_score' => ['required', 'numeric', 'min:30', 'max:95'],
            'surge_5d_pct' => ['required', 'numeric', 'min:0.05', 'max:2.0'],
            'surge_20d_pct' => ['required', 'numeric', 'min:0.10', 'max:5.0'],
            'volume_spike_mult' => ['required', 'numeric', 'min:1.5', 'max:20.0'],
            'volatility_high' => ['required', 'numeric', 'min:0.15', 'max:3.0'],
            'min_avg_value_idr' => ['required', 'numeric', 'min:0'],
            'gap_pct' => ['required', 'numeric', 'min:0.01', 'max:0.5'],
            'per_divergence_min' => ['required', 'numeric', 'min:10', 'max:500'],
            'min_free_float_pct' => ['required', 'numeric', 'min:0', 'max:50'],
            'max_top_holder_pct' => ['required', 'numeric', 'min:20', 'max:100'],
        ]);

        $config = SafetyConfig::query()->where('is_active', true)->orderByDesc('id')->firstOrFail();
        $old = $config->thresholds;
        $newThresholds = array_map('floatval', $validated);

        $config->update(['thresholds' => $newThresholds]);

        $audit->log('safety.config.updated', $config, ['thresholds' => $old], ['thresholds' => $newThresholds], $request);
        $refresh->execute($request->user()->id);

        return back()->with('status', 'safety-config-updated');
    }

    public function updateInstrument(
        Request $request,
        Instrument $instrument,
        AuditLogger $audit,
        RefreshMarketIntelligence $refresh,
    ): RedirectResponse {
        $validated = $request->validate([
            'is_suspended' => ['required', 'boolean'],
            'has_exchange_warning' => ['required', 'boolean'],
            'has_special_monitoring' => ['required', 'boolean'],
            'free_float_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'top_holder_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = InstrumentRegulatoryStatus::query()->find($instrument->id);
        $oldValues = $existing?->toArray();

        $status = InstrumentRegulatoryStatus::query()->updateOrCreate(
            ['instrument_id' => $instrument->id],
            [
                'is_suspended' => (bool) $validated['is_suspended'],
                'has_exchange_warning' => (bool) $validated['has_exchange_warning'],
                'has_special_monitoring' => (bool) $validated['has_special_monitoring'],
                'free_float_pct' => $validated['free_float_pct'] ?? null,
                'top_holder_pct' => $validated['top_holder_pct'] ?? null,
                'status_notes' => $validated['status_notes'] ?? null,
                'source_note' => 'Admin Regulatory Override',
                'is_demo' => false,
                'updated_at' => now('UTC'),
            ]
        );

        $audit->log('safety.regulatory.updated', $instrument, $oldValues, $status->toArray(), $request);
        $refresh->execute($request->user()->id);

        return back()->with('status', 'regulatory-status-updated');
    }
}
