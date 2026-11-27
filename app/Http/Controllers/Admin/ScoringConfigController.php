<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Domain\Analytics\Services\StockScoringService;
use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use App\Http\Controllers\Controller;
use App\Models\ScoringConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScoringConfigController extends Controller
{
    public function edit(): Response
    {
        $config = ScoringConfig::query()->where('is_active', true)->orderByDesc('id')->first();

        return Inertia::render('admin/scoring', [
            'config' => $config ?? [
                'code' => 'default',
                'name' => 'NADI Standard Quantitative Weights',
                'weights' => StockScoringService::DEFAULT_WEIGHTS,
                'version' => 'v1.0',
            ],
        ]);
    }

    public function update(Request $request, RefreshMarketIntelligence $refresh, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'weights' => ['required', 'array'],
            'weights.fundamentals' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.valuation' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.technical' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.risk' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.catalysts' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $weights = [
            'fundamentals' => (int) $validated['weights']['fundamentals'],
            'valuation' => (int) $validated['weights']['valuation'],
            'technical' => (int) $validated['weights']['technical'],
            'risk' => (int) $validated['weights']['risk'],
            'catalysts' => (int) $validated['weights']['catalysts'],
        ];

        $sum = array_sum($weights);
        if ($sum !== 100) {
            throw ValidationException::withMessages([
                'weights' => "Total bobot harus tepat 100% (saat ini {$sum}%).",
            ]);
        }

        $config = ScoringConfig::query()->firstOrCreate(
            ['code' => 'default'],
            [
                'name' => 'NADI Standard Quantitative Weights',
                'weights' => StockScoringService::DEFAULT_WEIGHTS,
                'version' => 'v1.0',
                'is_active' => true,
            ],
        );

        $old = [
            'name' => $config->name,
            'weights' => $config->weights,
            'version' => $config->version,
        ];

        $nextMinor = 0;
        if (preg_match('/^v1\.(\d+)$/', (string) $config->version, $m) === 1) {
            $nextMinor = ((int) $m[1]) + 1;
        }
        $newVersion = "v1.{$nextMinor}";

        $config->forceFill([
            'name' => $validated['name'],
            'weights' => $weights,
            'version' => $newVersion,
            'is_active' => true,
        ])->save();

        $refresh->execute($request->user()->id);

        $audit->log('scoring_config.updated', $config, $old, [
            'name' => $config->name,
            'weights' => $config->weights,
            'version' => $config->version,
        ], $request);

        return back()->with('status', 'scoring-updated');
    }
}
