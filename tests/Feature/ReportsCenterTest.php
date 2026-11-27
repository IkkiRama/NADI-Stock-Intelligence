<?php

namespace Tests\Feature;

use App\Domain\Reports\Services\PortfolioReportBuilderService;
use App\Models\ReportExport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reports_page_renders_all_five_report_types_for_seeded_portfolio(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        foreach (PortfolioReportBuilderService::REPORT_TYPES as $type) {
            $this->actingAs($admin)
                ->get("/reports?type={$type}&currency=IDR&range=1Y")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('reports/index')
                    ->where('filters.type', $type)
                    ->where('report.report_type', $type)
                    ->has('report.meta')
                    ->has('reportTypes', 5));
        }
    }

    public function test_print_view_renders_standalone_html_statement_with_demo_notice_and_logs_export(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/reports/print?type=executive_statement&currency=IDR')
            ->assertOk()
            ->assertSee('DEMO DATA NOTICE')
            ->assertSee('EXECUTIVE STATEMENT')
            ->assertSee('Consolidated Holdings')
            ->assertSee('BBCA');

        $this->actingAs($admin)
            ->get('/reports/print?type=performance_brief&currency=USD&range=YTD')
            ->assertOk()
            ->assertSee('DEMO DATA NOTICE')
            ->assertSee('PERFORMANCE BRIEF')
            ->assertSee('TWR');

        $this->assertDatabaseHas('report_exports', [
            'user_id' => $admin->id,
            'report_type' => 'executive_statement',
            'format' => 'print',
            'reporting_currency' => 'IDR',
        ]);
        $this->assertDatabaseHas('report_exports', [
            'user_id' => $admin->id,
            'report_type' => 'performance_brief',
            'format' => 'print',
            'reporting_currency' => 'USD',
        ]);
    }

    public function test_csv_and_json_exports_download_cleanly_and_log_history(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $csvRes = $this->actingAs($admin)
            ->get('/reports/export-csv?type=tax_realized_ledger&range=ALL&currency=IDR')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csvBody = $csvRes->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvBody);
        $this->assertStringContainsString('Report,tax_realized_ledger', $csvBody);
        $this->assertStringContainsString('TradeDate', $csvBody);
        $this->assertStringContainsString('BBCA', $csvBody);

        $jsonRes = $this->actingAs($admin)
            ->get('/reports/export-json?type=dividend_tax_schedule&currency=IDR')
            ->assertOk();

        $decoded = json_decode($jsonRes->getContent() ?: '{}', true);
        $this->assertIsArray($decoded);
        $this->assertSame('dividend_tax_schedule', $decoded['report_type'] ?? null);
        $this->assertArrayHasKey('dividends', $decoded);

        $this->assertDatabaseHas('report_exports', [
            'user_id' => $admin->id,
            'report_type' => 'tax_realized_ledger',
            'format' => 'csv',
        ]);
        $this->assertDatabaseHas('report_exports', [
            'user_id' => $admin->id,
            'report_type' => 'dividend_tax_schedule',
            'format' => 'json',
        ]);
    }

    public function test_empty_user_renders_safely_and_export_history_is_user_isolated(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($admin)->get('/reports/export-json?type=executive_statement')->assertOk();
        $this->assertGreaterThan(0, ReportExport::query()->where('user_id', $admin->id)->count());

        $this->actingAs($other)
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/index')
                ->where('report.report_type', 'executive_statement')
                ->has('exportHistory', 0));

        $this->actingAs($other)->get('/reports/export-csv?type=risk_rebalancing_plan')->assertOk();
        $this->actingAs($other)->get('/reports/print?type=dividend_tax_schedule')->assertOk();
    }
}
