<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlannedModuleController extends Controller
{
    public function show(string $slug): Response|RedirectResponse
    {
        if ($slug === 'heatmap') {
            return redirect()->route('heatmap.index');
        }

        if ($slug === 'screener') {
            return redirect()->route('screener.index');
        }

        if ($slug === 'opportunities') {
            return redirect()->route('opportunities.index');
        }

        if ($slug === 'stock-safety') {
            return redirect()->route('stock-safety.index');
        }

        if ($slug === 'entry-exit') {
            return redirect()->route('entry-exit.index');
        }

        if ($slug === 'alerts-calendar') {
            return redirect()->route('alerts-calendar.index');
        }

        if ($slug === 'portfolio') {
            return redirect()->route('portfolio.index');
        }

        if ($slug === 'portfolio-analytics') {
            return redirect()->route('portfolio.analytics');
        }

        if ($slug === 'risk-rebalancing') {
            return redirect()->route('portfolio.risk-rebalancing');
        }

        if ($slug === 'dividends') {
            return redirect()->route('dividends.index');
        }

        if ($slug === 'newsroom') {
            return redirect()->route('newsroom.index');
        }

        if ($slug === 'research-center') {
            return redirect()->route('research.index');
        }

        if ($slug === 'reports') {
            return redirect()->route('reports.index');
        }

        if ($slug === 'broker-intelligence') {
            return redirect()->route('broker-intelligence.index');
        }

        if ($slug === 'calculators') {
            return redirect()->route('calculators.index');
        }

        $module = config("nadi_modules.{$slug}");
        abort_if(! is_array($module), 404);

        return Inertia::render('modules/planned', [
            'module' => $module,
        ]);
    }
}
