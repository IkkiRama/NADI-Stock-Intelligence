<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Tighten\Ziggy\BladeRouteGenerator;

class SecurityHeaders
{
    /**
     * Apply strict nonce-based Content-Security-Policy and baseline security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Ensure Ziggy re-emits a fresh nonced route script per request even in long-lived workers/tests.
        BladeRouteGenerator::$generated = false;
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $scriptSrc = "'self' 'nonce-{$nonce}'";
        $styleSrc = "'self' 'unsafe-inline'";
        $connectSrc = "'self'";

        $hotPath = public_path('hot');
        if (is_file($hotPath)) {
            $hotUrl = trim((string) @file_get_contents($hotPath));
            if ($hotUrl !== '' && preg_match('#^(https?://[^\s/]+)#i', $hotUrl, $m) === 1) {
                $httpOrigin = $m[1];
                $wsOrigin = preg_replace('#^http#i', 'ws', $httpOrigin);
                $scriptSrc .= " {$httpOrigin}";
                $connectSrc .= " {$httpOrigin} {$wsOrigin}";
            }
        }

        $cspDirectives = [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            "style-src {$styleSrc}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src {$connectSrc}",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $cspDirectives));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
