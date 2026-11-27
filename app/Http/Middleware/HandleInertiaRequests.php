<?php

namespace App\Http\Middleware;

use App\Models\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        if ($user) {
            $user->loadMissing(['roles:id,slug', 'settings']);
        }

        $locale = app()->getLocale();
        $defaults = config('nadi.defaults');
        $settings = $user?->settings;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('nadi.version'),
            'demoMode' => (bool) config('nadi.market_data.demo.enabled', true),
            'locale' => $locale,
            'translations' => $this->loadTranslations($locale),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at,
                    'roles' => $user->roles->pluck('slug')->values()->all(),
                ] : null,
                'can' => [
                    'accessAdmin' => $user ? $user->can('access-admin') : false,
                ],
            ],
            'settings' => [
                'locale' => $settings?->locale ?? $locale,
                'appearance' => $settings?->appearance ?? ($defaults['appearance'] ?? 'system'),
                'density' => $settings?->density ?? ($defaults['density'] ?? 'comfortable'),
                'analysis_mode' => $settings?->analysis_mode ?? ($defaults['analysis_mode'] ?? 'simple'),
                'default_currency' => $settings?->default_currency ?? ($defaults['currency'] ?? 'IDR'),
                'default_market' => $settings?->default_market ?? ($defaults['market'] ?? 'IDX'),
                'default_timeframe' => $settings?->default_timeframe ?? ($defaults['timeframe'] ?? '1Y'),
                'timezone' => $settings?->timezone ?? ($defaults['timezone'] ?? 'Asia/Jakarta'),
                'dashboard_layout' => $settings?->dashboard_layout,
            ],
            'featureFlags' => $this->loadFeatureFlags(),
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        $supported = config('nadi.locales', ['id', 'en']);
        $safeLocale = in_array($locale, $supported, true) ? $locale : 'id';
        $path = lang_path($safeLocale.'.json');

        if (! is_file($path)) {
            return [];
        }

        $mtime = filemtime($path) ?: 0;

        return Cache::remember("nadi:i18n:{$safeLocale}:{$mtime}", now()->addHours(6), function () use ($path): array {
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * @return array<string, bool>
     */
    private function loadFeatureFlags(): array
    {
        return Cache::remember('nadi:feature_flags', now()->addMinutes(5), function (): array {
            if (! Schema::hasTable('feature_flags')) {
                return [];
            }

            return FeatureFlag::query()
                ->pluck('enabled', 'key')
                ->map(fn ($v) => (bool) $v)
                ->all();
        });
    }
}
