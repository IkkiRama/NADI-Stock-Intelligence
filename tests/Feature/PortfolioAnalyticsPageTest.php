<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortfolioAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_portfolio_analytics_page_renders_twr_xirr_attribution_and_benchmark_filters(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/portfolio-analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/analytics')
                ->where('filters.benchmark', 'IHSG')
                ->where('filters.currency', 'IDR')
                ->where('windowMeta.has_data', true)
                ->where('twr.has_data', true)
                ->where('xirr.status', 'converged')
                ->where('attribution.has_data', true)
                ->where('benchmark.has_data', true)
                ->where('benchmark.currency_mismatch_warning', false)
                ->has('benchmarks', 8)
            );

        // Switching to foreign USD benchmark SPX in native_index mode triggers explicit currency mismatch warning
        $this->actingAs($admin)
            ->get('/portfolio-analytics?benchmark=SPX&benchmark_ccy_mode=native_index&range=3M&method=fifo&currency=IDR')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/analytics')
                ->where('filters.range', '3M')
                ->where('filters.method', 'fifo')
                ->where('benchmark.code', 'SPX')
                ->where('benchmark.index_currency', 'USD')
                ->where('benchmark.currency_mode', 'native_index')
                ->where('benchmark.currency_mismatch_warning', true)
            );

        // Switching back to FX-aligned mode clears mismatch warning and marks conversion active
        $this->actingAs($admin)
            ->get('/portfolio-analytics?benchmark=SPX&benchmark_ccy_mode=reporting_aligned&currency=IDR')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/analytics')
                ->where('benchmark.code', 'SPX')
                ->where('benchmark.currency_aligned', true)
                ->where('benchmark.currency_converted', true)
                ->where('benchmark.currency_mismatch_warning', false)
            );
    }

    public function test_empty_user_portfolio_renders_gracefully_with_no_cross_user_leakage(): void
    {
        $emptyUser = User::factory()->create();

        $this->actingAs($emptyUser)
            ->get('/portfolio-analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/analytics')
                ->where('windowMeta.has_data', false)
                ->where('twr.has_data', false)
                ->where('xirr.xirr_annualized', null)
                ->where('attribution.has_data', false)
                ->where('benchmark.has_data', false)
                ->has('accounts', 0)
            );
    }
}
