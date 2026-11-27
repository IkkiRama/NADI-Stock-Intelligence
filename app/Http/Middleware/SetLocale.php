<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('nadi.locales', ['id', 'en']);
        $candidate = $request->user()?->settings?->locale
            ?? $request->session()->get('locale')
            ?? config('nadi.defaults.locale', 'id');

        $locale = in_array($candidate, $supported, true) ? $candidate : 'id';
        app()->setLocale($locale);

        return $next($request);
    }
}
