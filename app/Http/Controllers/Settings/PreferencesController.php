<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $user->loadMissing('settings');
        $defaults = config('nadi.defaults');

        return Inertia::render('settings/preferences', [
            'preferences' => [
                'locale' => $user->settings?->locale ?? $defaults['locale'],
                'appearance' => $user->settings?->appearance ?? $defaults['appearance'],
                'density' => $user->settings?->density ?? $defaults['density'],
                'analysis_mode' => $user->settings?->analysis_mode ?? $defaults['analysis_mode'],
                'default_currency' => $user->settings?->default_currency ?? $defaults['currency'],
                'default_market' => $user->settings?->default_market ?? $defaults['market'],
                'default_timeframe' => $user->settings?->default_timeframe ?? $defaults['timeframe'],
                'timezone' => $user->settings?->timezone ?? $defaults['timezone'],
            ],
            'options' => [
                'locales' => config('nadi.locales'),
                'currencies' => config('nadi.currencies'),
                'exchanges' => config('nadi.exchanges'),
                'timeframes' => config('nadi.timeframes'),
                'timezones' => [
                    'Asia/Jakarta',
                    'Asia/Singapore',
                    'Asia/Shanghai',
                    'Asia/Hong_Kong',
                    'America/New_York',
                    'UTC',
                ],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'required', 'string', Rule::in(config('nadi.locales', ['id', 'en']))],
            'appearance' => ['sometimes', 'required', 'string', Rule::in(['light', 'dark', 'system'])],
            'density' => ['sometimes', 'required', 'string', Rule::in(['compact', 'comfortable'])],
            'analysis_mode' => ['sometimes', 'required', 'string', Rule::in(['simple', 'pro'])],
            'default_currency' => ['sometimes', 'required', 'string', Rule::in(config('nadi.currencies', ['IDR']))],
            'default_market' => ['sometimes', 'required', 'string', Rule::in(config('nadi.exchanges', ['IDX']))],
            'default_timeframe' => ['sometimes', 'required', 'string', Rule::in(config('nadi.timeframes', ['1Y']))],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ]);

        $user = $request->user();
        $defaults = config('nadi.defaults');

        $settings = $user->settings()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'locale' => $defaults['locale'] ?? 'id',
                'appearance' => $defaults['appearance'] ?? 'system',
                'density' => $defaults['density'] ?? 'comfortable',
                'analysis_mode' => $defaults['analysis_mode'] ?? 'simple',
                'default_currency' => $defaults['currency'] ?? 'IDR',
                'default_market' => $defaults['market'] ?? 'IDX',
                'default_timeframe' => $defaults['timeframe'] ?? '1Y',
                'timezone' => $defaults['timezone'] ?? 'Asia/Jakarta',
            ],
        );

        if (! empty($validated)) {
            $settings->update($validated);
        }

        if (isset($validated['locale'])) {
            $request->session()->put('locale', $validated['locale']);
            app()->setLocale($validated['locale']);
        }

        $response = back()->with('status', 'preferences-updated');

        if (isset($validated['appearance'])) {
            $response->withCookie(cookie('appearance', $validated['appearance'], 60 * 24 * 365, '/', null, null, false));
        }

        return $response;
    }
}
