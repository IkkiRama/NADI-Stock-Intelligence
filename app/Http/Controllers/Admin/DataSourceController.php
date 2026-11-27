<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataSourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/data-sources', [
            'dataSources' => DataSource::query()->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, DataSource $dataSource, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $oldValues = ['is_enabled' => (bool) $dataSource->is_enabled];
        $dataSource->forceFill(['is_enabled' => (bool) $validated['is_enabled']])->save();

        $audit->log('data_source.updated', $dataSource, $oldValues, ['is_enabled' => (bool) $dataSource->is_enabled], $request);

        return back()->with('status', 'data-source-updated');
    }
}
