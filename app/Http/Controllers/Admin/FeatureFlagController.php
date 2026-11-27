<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class FeatureFlagController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/feature-flags', [
            'flags' => FeatureFlag::query()->orderBy('key')->get(),
        ]);
    }

    public function update(Request $request, FeatureFlag $featureFlag, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $oldValues = ['enabled' => (bool) $featureFlag->enabled];
        $featureFlag->forceFill(['enabled' => (bool) $validated['enabled']])->save();

        Cache::forget('nadi:feature_flags');
        $audit->log('feature_flag.updated', $featureFlag, $oldValues, ['enabled' => (bool) $featureFlag->enabled], $request);

        return back()->with('status', 'feature-flag-updated');
    }
}
