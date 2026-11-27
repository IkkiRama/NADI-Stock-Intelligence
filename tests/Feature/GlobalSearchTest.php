<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ExchangeSeeder::class, MarketUniverseSeeder::class]);
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/search?q=BBCA')->assertUnauthorized();
    }

    public function test_search_returns_grouped_results_and_supports_typo_tolerance(): void
    {
        $user = User::factory()->create();

        $exactRes = $this->actingAs($user)
            ->getJson('/search?q=BBCA')
            ->assertOk()
            ->assertJsonStructure([
                'query',
                'stocks',
                'indices',
                'exchanges',
                'pages',
            ]);

        $this->assertSame('BBCA', $exactRes->json('stocks.0.symbol'));

        $typoTicker = $this->actingAs($user)
            ->getJson('/search?q=BBAC')
            ->assertOk();

        $this->assertContains('BBCA', collect($typoTicker->json('stocks'))->pluck('symbol')->all());

        $typoCompany = $this->actingAs($user)
            ->getJson('/search?q=tencnt')
            ->assertOk();

        $this->assertContains('0700', collect($typoCompany->json('stocks'))->pluck('symbol')->all());
    }
}
