<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use App\Models\UserAlert;
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

class Phase3ModulesTest extends TestCase
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

    public function test_opportunities_excludes_safeguard_triggered_stocks_and_stock_safety_renders(): void
    {
        $user = User::factory()->create();

        // GOTO has demo special monitoring -> safeguard_triggered = true -> MUST be excluded from Opportunities
        $this->actingAs($user)
            ->get('/opportunities')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intelligence/opportunities')
                ->where('opportunities.data', function ($rows): bool {
                    $symbols = collect($rows)->pluck('symbol')->all();

                    return ! in_array('GOTO', $symbols, true);
                })
            );

        $this->actingAs($user)
            ->get('/stock-safety?safeguard_only=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intelligence/stock-safety')
                ->where('assessments.data', function ($rows): bool {
                    $symbols = collect($rows)->pluck('symbol')->all();

                    return in_array('GOTO', $symbols, true);
                })
            );

        $this->actingAs($user)
            ->get('/screener?exclude_flagged=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/screener')
                ->where('results.data', function ($rows): bool {
                    $symbols = collect($rows)->pluck('symbol')->all();

                    return ! in_array('GOTO', $symbols, true);
                })
            );
    }

    public function test_entry_exit_and_position_sizing_endpoint_work(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/entry-exit?exchange=IDX&symbol=BBCA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intelligence/entry-exit')
                ->where('instrument.symbol', 'BBCA')
                ->has('signalsByHorizon.swing')
                ->has('safety')
            );

        $this->actingAs($user)
            ->postJson('/entry-exit/position-size', [
                'capital' => 100000000,
                'max_risk_pct' => 1.5,
                'entry_price' => 10000,
                'stop_loss' => 9500,
                'target_price' => 11200,
                'lot_size' => 100,
                'buy_fee_pct' => 0.0015,
                'sell_fee_pct' => 0.0025,
                'slippage_pct' => 0.0010,
            ])
            ->assertOk()
            ->assertJsonStructure([
                'lots', 'lot_size', 'shares', 'total_capital_required', 'actual_capital_at_risk', 'net_rr',
            ]);

        // Invalid stop >= entry returns 422
        $this->actingAs($user)
            ->postJson('/entry-exit/position-size', [
                'capital' => 100000000,
                'max_risk_pct' => 1.5,
                'entry_price' => 9500,
                'stop_loss' => 9600,
                'lot_size' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_alerts_calendar_crud_is_user_scoped_and_planned_redirects_work(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $bbca = Instrument::query()->where('symbol', 'BBCA')->firstOrFail();

        $this->actingAs($userA)
            ->get('/alerts-calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tools/alerts-calendar')
                ->has('corporateActions')
            );

        $this->actingAs($userA)
            ->post('/alerts', [
                'instrument_id' => $bbca->id,
                'alert_type' => 'price_above',
                'threshold_value' => 1,
                'notes' => 'Test trigger',
            ])
            ->assertRedirect();

        $alert = UserAlert::query()->where('user_id', $userA->id)->firstOrFail();

        // User B cannot toggle or delete User A's alert
        $this->actingAs($userB)->patch("/alerts/{$alert->id}/toggle")->assertForbidden();
        $this->actingAs($userB)->delete("/alerts/{$alert->id}")->assertForbidden();

        // User A can toggle and delete
        $this->actingAs($userA)->patch("/alerts/{$alert->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $alert->fresh()->is_active);
        $this->actingAs($userA)->delete("/alerts/{$alert->id}")->assertRedirect();
        $this->assertDatabaseMissing('user_alerts', ['id' => $alert->id]);

        // Phase 3 planned route redirects
        $this->actingAs($userA)->get('/segera/opportunities')->assertRedirect('/opportunities');
        $this->actingAs($userA)->get('/segera/stock-safety')->assertRedirect('/stock-safety');
        $this->actingAs($userA)->get('/segera/entry-exit')->assertRedirect('/entry-exit');
        $this->actingAs($userA)->get('/segera/alerts-calendar')->assertRedirect('/alerts-calendar');
    }
}
