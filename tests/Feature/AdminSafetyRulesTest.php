<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Instrument;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\DemoMarketIntelligenceSeeder;
use Database\Seeders\DemoPriceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SafetyConfigSeeder;
use Database\Seeders\ScoringConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSafetyRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            ExchangeSeeder::class,
            MarketUniverseSeeder::class,
            DataSourceSeeder::class,
            ScoringConfigSeeder::class,
            SafetyConfigSeeder::class,
            DemoPriceSeeder::class,
            DemoMarketIntelligenceSeeder::class,
        ]);
    }

    public function test_non_admin_cannot_access_safety_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/safety-rules')->assertForbidden();
        $this->actingAs($user)->patch('/admin/safety-rules', [])->assertForbidden();
    }

    public function test_admin_can_update_safety_thresholds_and_regulatory_status_with_audit_logs(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->roles()->sync([$adminRole->id]);

        $this->actingAs($admin)
            ->get('/admin/safety-rules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/safety-rules'));

        $this->actingAs($admin)
            ->patch('/admin/safety-rules', [
                'safeguard_score' => 60,
                'surge_5d_pct' => 0.20,
                'surge_20d_pct' => 0.45,
                'volume_spike_mult' => 3.0,
                'volatility_high' => 0.45,
                'min_avg_value_idr' => 2000000000,
                'gap_pct' => 0.05,
                'per_divergence_min' => 40,
                'min_free_float_pct' => 10,
                'max_top_holder_pct' => 80,
            ])
            ->assertRedirect();

        $this->assertTrue(AuditLog::query()->where('action', 'safety.config.updated')->exists());

        $bbca = Instrument::query()->where('symbol', 'BBCA')->firstOrFail();

        $this->actingAs($admin)
            ->patch("/admin/safety-rules/instruments/{$bbca->id}", [
                'is_suspended' => true,
                'has_exchange_warning' => false,
                'has_special_monitoring' => false,
                'free_float_pct' => 42.5,
                'top_holder_pct' => 54.0,
                'status_notes' => 'Test suspensi admin',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $bbca->safetyAssessment()->firstOrFail()->safeguard_triggered);
        $this->assertTrue(
            $bbca->tradingSignals()->where('horizon', 'swing')->where('is_latest', true)->firstOrFail()->safeguard_suppressed
        );
        $this->assertTrue(AuditLog::query()->where('action', 'safety.regulatory.updated')->exists());
    }
}
