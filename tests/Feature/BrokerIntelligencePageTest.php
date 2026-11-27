<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrokerIntelligencePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_broker_intelligence_renders_eod_flow_directory_personal_analytics_and_depth_notice(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/broker-intelligence?symbol=BBCA&window=20D&currency=IDR')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('broker-intelligence/index')
                ->where('filters.symbol', 'BBCA')
                ->where('filters.window', '20D')
                ->where('stockFlow.instrument.symbol', 'BBCA')
                ->where('marketDepthStatus.licensed', false)
                ->where('marketDepthStatus.enabled', false)
                ->has('directory', 16)
                ->has('personalAnalytics.accounts', 2)
                ->has('marketFlowMovers.top_accumulated'));

        $csvRes = $this->actingAs($admin)
            ->get('/broker-intelligence/export-csv?symbol=BBCA&window=5D')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csvBody = $csvRes->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvBody);
        $this->assertStringContainsString('Symbol,BBCA', $csvBody);
        $this->assertStringContainsString('BrokerCode', $csvBody);
    }

    public function test_stock_detail_shows_live_broker_flow_and_personal_analytics_is_user_isolated(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($admin)
            ->get('/stocks/idx/bbca')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/show')
                ->where('brokerFlow.symbol', 'BBCA')
                ->has('brokerFlow.regime'));

        $this->actingAs($other)
            ->get('/broker-intelligence')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('broker-intelligence/index')
                ->has('personalAnalytics.accounts', 0)
                ->where('personalAnalytics.summary.total_trades', 0)
                ->has('directory', 16));
    }

    public function test_admin_can_import_broker_summaries_daily_csv(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $file = UploadedFile::fake()->createWithContent(
            'broker_summary.csv',
            "exchange_code,symbol,trade_date,broker_code,buy_volume,sell_volume,buy_value,sell_value\nIDX,BBCA,2026-11-27,AK,12000,1000,114000000,9500000\n"
        );

        $this->actingAs($admin)
            ->post('/admin/imports/csv', [
                'dataset' => 'broker_summaries_daily',
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'csv-imported');

        $this->assertDatabaseHas('data_import_runs', [
            'dataset' => 'broker_summaries_daily',
            'status' => 'completed',
            'rows_inserted' => 1,
        ]);
    }
}
