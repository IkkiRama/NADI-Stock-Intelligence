<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PlannedModuleController extends Controller
{
    public function show(string $slug): Response
    {
        $module = config("nadi_modules.{$slug}");
        abort_if(! is_array($module), 404);

        return Inertia::render('modules/planned', [
            'module' => $module,
        ]);
    }
}
