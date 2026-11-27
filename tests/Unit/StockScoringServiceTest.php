<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\StockScoringService;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\ScoringConfig;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoring_bounds_custom_weights_and_missing_coverage(): void
    {
        $this->seed(ExchangeSeeder::class);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'SCOR',
            'name' => 'Score Test Tbk',
            'currency' => 'IDR',
            'sector' => 'Financials',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        $config = new ScoringConfig([
            'code' => 'custom',
            'name' => 'Custom',
            'weights' => [
                'fundamentals' => 40,
                'valuation' => 20,
                'technical' => 20,
                'risk' => 10,
                'catalysts' => 10,
            ],
            'version' => 'v1.1',
        ]);

        $svc = new StockScoringService();

        $full = $svc->scoreInstrument(
            $inst,
            '1000',
            [
                'per' => '10.0',
                'pbv' => '1.5',
                'roe' => '0.20',
                'net_margin' => '0.22',
                'revenue_growth_yoy' => '0.15',
                'earnings_growth_yoy' => '0.18',
                'dividend_yield' => '0.04',
                'der' => '5.0',
                'is_financial_sector' => true,
                'is_demo' => true,
            ],
            [
                'sma_50' => '950',
                'sma_200' => '900',
                'rsi_14' => '58',
                'volatility_20d' => '0.20',
                'relative_strength_1m' => '0.05',
            ],
            '2026-11-26',
            ['per' => 15.0, 'pbv' => 2.0],
            $config,
        );

        $this->assertSame('100.00', $full['data_coverage']);
        $this->assertSame('v1.1', $full['calculation_version']);
        $this->assertGreaterThan(60.0, (float) $full['overall_score']);
        $this->assertLessThanOrEqual(100.0, (float) $full['overall_score']);
        $this->assertNotEmpty($full['reasons']);

        // Missing fundamentals + technicals reduces coverage honestly
        $partial = $svc->scoreInstrument($inst, '1000', null, null, '2026-11-26', [], $config);
        $this->assertSame('10.00', $partial['data_coverage']); // only catalysts weight (10%) covered
        $this->assertContains('financial_statements', $partial['missing_indicators']);
        $this->assertContains('technicals', $partial['missing_indicators']);
    }
}
