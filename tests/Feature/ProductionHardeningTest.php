<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_strict_csp_nonce_and_security_headers_are_enforced_without_unsafe_inline_scripts(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $guestUrls = ['/', '/login'];
        foreach ($guestUrls as $url) {
            $res = $this->get($url)->assertOk();
            $csp = (string) $res->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
            $this->assertSame('none', $res->headers->get('X-Permitted-Cross-Domain-Policies'));
        }

        $authHtmlUrls = [
            '/dashboard',
            '/reports/print?type=executive_statement',
            '/strategy-lab',
        ];
        foreach ($authHtmlUrls as $url) {
            $res = $this->actingAs($admin)->get($url)->assertOk();
            $csp = (string) $res->headers->get('Content-Security-Policy');
            preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $matches);
            $this->assertNotEmpty($matches[1] ?? null, "Missing CSP nonce for {$url}");
            $nonce = $matches[1];

            // Verify script-src segment itself never allows unsafe-inline or unsafe-eval
            preg_match('/script-src ([^;]+)/', $csp, $scriptMatch);
            $scriptDirective = $scriptMatch[1] ?? '';
            $this->assertStringNotContainsString('unsafe-inline', $scriptDirective);
            $this->assertStringNotContainsString('unsafe-eval', $scriptDirective);

            $html = $res->getContent() ?: '';
            $this->assertStringNotContainsString('onclick=', $html);

            if (preg_match_all('/<script\b([^>]*)>/i', $html, $scriptTags)) {
                foreach ($scriptTags[1] as $attrs) {
                    $this->assertStringContainsString("nonce=\"{$nonce}\"", $attrs, "Un-nonced script tag found on {$url}");
                }
            }
        }

        $httpsRes = $this->actingAs($admin)->get('https://localhost/dashboard')->assertOk();
        $this->assertStringContainsString('max-age=31536000', (string) $httpsRes->headers->get('Strict-Transport-Security'));
    }

    public function test_nadi_doctor_command_health_endpoint_and_admin_system_health_succeed(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->artisan('nadi:doctor')->assertExitCode(0);
        $this->get('/up')->assertOk();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->where('systemHealth.version', '1.0.0')
                ->where('systemHealth.csp_enabled', true));
    }

    public function test_all_nadi_workspace_modules_render_with_zero_planned_placeholders(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->assertSame('1.0.0', config('nadi.version'));
        $this->assertSame([], config('nadi_modules'));

        $routes = [
            '/dashboard',
            '/markets',
            '/heatmap',
            '/stocks',
            '/stocks/idx/bbca',
            '/screener',
            '/opportunities',
            '/stock-safety',
            '/entry-exit',
            '/strategy-lab',
            '/portfolio',
            '/portfolio-analytics',
            '/dividends',
            '/risk-rebalancing',
            '/reports',
            '/newsroom',
            '/broker-intelligence',
            '/research-center',
            '/alerts-calendar',
            '/calculators',
        ];

        foreach ($routes as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }
}
