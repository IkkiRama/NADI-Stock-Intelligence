<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RiskRebalancingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_risk_rebalancing_renders_with_risk_metrics_correlation_fx_and_target_updates(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/risk-rebalancing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/risk-rebalancing')
                ->where('risk.has_data', true)
                ->where('risk.sufficient_observations', true)
                ->where('correlation.has_data', true)
                ->where('rebalance.has_data', true)
                ->where('rebalance.is_default_preview', true)
            );

        // Apply Equal-Weight preset and confirm custom preview becomes saved targets
        $this->actingAs($admin)
            ->post('/risk-rebalancing/presets', ['preset' => 'equal_weight'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get('/risk-rebalancing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rebalance.is_default_preview', false)
            );

        // Saving targets exceeding 100% combined with cash buffer must fail validation cleanly
        $bbcaRow = $admin->ensureDefaultPortfolio()->rebalanceTargets()->firstOrFail();
        $this->actingAs($admin)
            ->put('/risk-rebalancing/targets', [
                'cash_buffer_pct' => 10,
                'drift_band_pct' => 2.5,
                'risk_free_rate_pct' => 6.0,
                'exclude_safety_flagged_buys' => true,
                'targets' => [
                    ['instrument_id' => $bbcaRow->instrument_id, 'target_weight_pct' => 95],
                ],
            ])
            ->assertSessionHasErrors('targets');

        // Valid custom target update persists and updates simulation
        $this->actingAs($admin)
            ->put('/risk-rebalancing/targets', [
                'cash_buffer_pct' => 10,
                'drift_band_pct' => 2.0,
                'risk_free_rate_pct' => 5.5,
                'exclude_safety_flagged_buys' => false,
                'targets' => [
                    ['instrument_id' => $bbcaRow->instrument_id, 'target_weight_pct' => 90],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'rebalance-targets-saved');

        $this->actingAs($admin)
            ->get('/risk-rebalancing?inflow=50000000')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.rf_pct', '5.50')
                ->where('rebalance.cash_buffer_pct', '10.00')
                ->where('rebalance.allocated_target_pct', '90.00')
            );
    }

    public function test_empty_user_portfolio_renders_risk_rebalancing_gracefully(): void
    {
        $emptyUser = User::factory()->create();

        $this->actingAs($emptyUser)
            ->get('/risk-rebalancing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/risk-rebalancing')
                ->where('risk.has_data', false)
                ->where('correlation.has_data', false)
                ->where('rebalance.has_data', false)
                ->has('accounts', 0)
            );
    }
}
