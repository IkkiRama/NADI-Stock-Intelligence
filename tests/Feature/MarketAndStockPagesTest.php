<?php

namespace Tests\Feature;

use App\Models\Instrument;
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

class MarketAndStockPagesTest extends TestCase
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

    public function test_markets_index_and_index_detail_render_with_bars(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/markets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('markets/index'));

        $this->actingAs($user)
            ->get('/markets/indices/ihsg')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('markets/index-show')
                ->where('index.code', 'IHSG')
                ->has('bars', 260)
            );
    }

    public function test_stock_explorer_and_detail_render_with_phase2_intelligence_and_unknown_routes_return_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/stocks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('stocks/index'));

        $this->actingAs($user)
            ->get('/stocks/idx/bbca')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/show')
                ->where('instrument.symbol', 'BBCA')
                ->has('bars', 260)
                ->has('fundamentals')
                ->has('technicals')
                ->has('score')
                ->has('safety')
                ->has('signalsByHorizon.swing')
                ->has('corporateActions')
                ->has('indicatorSeries.sma20')
            );

        $this->actingAs($user)->get('/stocks/idx/unknown')->assertNotFound();
        $this->actingAs($user)->get('/markets/indices/unknown')->assertNotFound();
        $this->actingAs($user)->get('/segera/unknown-module')->assertNotFound();
        $this->actingAs($user)->get('/segera/heatmap')->assertRedirect('/heatmap');
        $this->actingAs($user)->get('/segera/screener')->assertRedirect('/screener');
        $this->actingAs($user)->get('/segera/opportunities')->assertRedirect('/opportunities');
        $this->actingAs($user)->get('/segera/portfolio')->assertRedirect('/portfolio');
        $this->actingAs($user)->get('/segera/portfolio-analytics')->assertRedirect('/portfolio-analytics');
        $this->actingAs($user)->get('/segera/risk-rebalancing')->assertOk();
    }

    public function test_watchlist_add_and_remove_are_scoped_to_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $bbca = Instrument::query()->where('symbol', 'BBCA')->firstOrFail();

        $this->actingAs($userA)
            ->post('/watchlist/items', ['instrument_id' => $bbca->id])
            ->assertRedirect();

        $this->assertTrue(
            $userA->watchlists()->firstOrFail()->items()->where('instrument_id', $bbca->id)->exists()
        );
        $this->assertSame(0, $userB->watchlists()->count());

        $this->actingAs($userB)
            ->delete("/watchlist/items/{$bbca->id}")
            ->assertRedirect();

        $this->assertTrue(
            $userA->watchlists()->firstOrFail()->items()->where('instrument_id', $bbca->id)->exists()
        );

        $this->actingAs($userA)
            ->delete("/watchlist/items/{$bbca->id}")
            ->assertRedirect();

        $this->assertFalse(
            $userA->watchlists()->firstOrFail()->items()->where('instrument_id', $bbca->id)->exists()
        );
    }
}
