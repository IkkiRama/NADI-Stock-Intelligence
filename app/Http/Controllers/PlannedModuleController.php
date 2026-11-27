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

        $module = config("nadi_modules.{$slug}");
        abort_if(! is_array($module), 404);

        return Inertia::render('modules/planned', [
            'module' => $module,
        ]);
    }
}
