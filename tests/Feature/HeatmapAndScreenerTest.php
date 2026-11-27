<?php

namespace Tests\Feature;

use App\Models\SavedScreener;
use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\DemoMarketIntelligenceSeeder;
use Database\Seeders\DemoPriceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScoringConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HeatmapAndScreenerTest extends TestCase
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
            DemoPriceSeeder::class,
            DemoMarketIntelligenceSeeder::class,
        ]);
    }

    public function test_heatmap_renders_sector_treemap_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/heatmap?exchange=IDX')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('markets/heatmap')
                ->where('filters.exchange', 'IDX')
                ->where('isDemo', true)
                ->has('sectors')
            );
    }

    public function test_screener_filters_presets_isolation_and_csv_export(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // GOTO is seeded with negative net_income -> profitable_only=1 must exclude GOTO
        $this->actingAs($userA)
            ->get('/screener?exchange=IDX&profitable_only=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/screener')
                ->where('results.data', function ($rows): bool {
                    $symbols = collect($rows)->pluck('symbol')->all();

                    return in_array('BBCA', $symbols, true) && ! in_array('GOTO', $symbols, true);
                })
            );

        // Save preset
        $this->actingAs($userA)
            ->post('/screener/presets', [
                'name' => 'IDX Quality',
                'filters' => ['exchange' => 'IDX', 'profitable_only' => '1'],
                'sort_by' => 'overall_score',
                'sort_dir' => 'desc',
            ])
            ->assertRedirect();

        $preset = SavedScreener::query()->where('user_id', $userA->id)->firstOrFail();

        // User B cannot delete User A's preset
        $this->actingAs($userB)
            ->delete("/screener/presets/{$preset->id}")
            ->assertForbidden();

        // User A can delete own preset
        $this->actingAs($userA)
            ->delete("/screener/presets/{$preset->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('saved_screeners', ['id' => $preset->id]);

        // CSV Export
        $csvRes = $this->actingAs($userA)
            ->get('/screener/export?exchange=IDX')
            ->assertOk();

        $csvRes->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $csvRes->streamedContent();
        $this->assertStringContainsString('Exchange,Symbol,Name', $content);
        $this->assertStringContainsString('IDX,BBCA', $content);
    }
}
