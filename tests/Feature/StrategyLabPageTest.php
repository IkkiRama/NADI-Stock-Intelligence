<?php

namespace Tests\Feature;

use App\Domain\StrategyLab\Services\StrategyBacktestService;
use App\Models\StrategyBacktestRun;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StrategyLabPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_strategy_lab_renders_all_four_strategies_and_exports_csv(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        foreach (StrategyBacktestService::STRATEGIES as $code) {
            $this->actingAs($admin)
                ->get("/strategy-lab?symbol=BBCA&strategy={$code}&range=1Y")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('strategy-lab/index')
                    ->where('backtest.instrument.symbol', 'BBCA')
                    ->where('backtest.strategy', $code)
                    ->where('backtest.safeguards.execution_rule', 'next_bar_open')
                    ->where('backtest.safeguards.sl_tp_tie_break', 'stop_loss_first')
                    ->has('backtest.equity_curve'));
        }

        $csvRes = $this->actingAs($admin)
            ->get('/strategy-lab/export-csv?symbol=BBCA&strategy=sma_crossover&range=1Y')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csvBody = $csvRes->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvBody);
        $this->assertStringContainsString('Symbol,BBCA', $csvBody);
        $this->assertStringContainsString('Next-Bar Open (t+1)', $csvBody);
    }

    public function test_saving_loading_and_deleting_backtest_runs_is_user_isolated(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($admin)
            ->post('/strategy-lab/runs', [
                'symbol' => 'MSFT',
                'name' => 'MSFT Momentum Check',
                'strategy' => 'macd_momentum',
                'range' => '1Y',
                'stop_loss_pct' => '0.08',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'backtest-saved');

        $run = StrategyBacktestRun::query()->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('macd_momentum', $run->strategy_code);

        // Other user cannot see or delete admin's snapshot
        $this->actingAs($other)
            ->get('/strategy-lab?symbol=MSFT')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('strategy-lab/index')
                ->has('savedRuns', 0));

        $this->actingAs($other)
            ->delete("/strategy-lab/runs/{$run->id}")
            ->assertForbidden();

        // Owner can delete cleanly
        $this->actingAs($admin)
            ->delete("/strategy-lab/runs/{$run->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('strategy_backtest_runs', ['id' => $run->id]);
    }
}
