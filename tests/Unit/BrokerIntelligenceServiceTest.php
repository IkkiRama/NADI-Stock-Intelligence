<?php

namespace Tests\Unit;

use App\Domain\BrokerIntelligence\Services\BrokerIntelligenceService;
use App\Domain\MarketData\Services\CsvMarketImporter;
use App\Models\BrokerSummaryDaily;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use Database\Seeders\BrokerSeeder;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrokerIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class, BrokerSeeder::class]);
    }

    public function test_computes_exact_cr3_foreign_flow_and_strong_accumulation_regime(): void
    {
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $bbca = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'type' => 'equity',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        $rows = [
            // Foreign & inst heavy buyers (AK +600k, BK +300k, CC +100k => positive sum = 1,000,000, top3 = 1,000,000 => CR3 Buy = 1.0)
            ['code' => 'AK', 'cat' => 'foreign_institutional', 'bvol' => 700, 'svol' => 100, 'bval' => '700000.0000', 'sval' => '100000.0000'],
            ['code' => 'BK', 'cat' => 'foreign_institutional', 'bvol' => 400, 'svol' => 100, 'bval' => '400000.0000', 'sval' => '100000.0000'],
            ['code' => 'CC', 'cat' => 'local_institutional', 'bvol' => 200, 'svol' => 100, 'bval' => '200000.0000', 'sval' => '100000.0000'],
            // Retail spread sellers (YP -300k, XL -250k, XC -250k, PD -200k => neg abs sum = 1,000,000, top3 = 800,000 => CR3 Sell = 0.8)
            ['code' => 'YP', 'cat' => 'local_retail', 'bvol' => 100, 'svol' => 400, 'bval' => '100000.0000', 'sval' => '400000.0000'],
            ['code' => 'XL', 'cat' => 'local_retail', 'bvol' => 100, 'svol' => 350, 'bval' => '100000.0000', 'sval' => '350000.0000'],
            ['code' => 'XC', 'cat' => 'local_retail', 'bvol' => 100, 'svol' => 350, 'bval' => '100000.0000', 'sval' => '350000.0000'],
            ['code' => 'PD', 'cat' => 'local_retail', 'bvol' => 100, 'svol' => 300, 'bval' => '100000.0000', 'sval' => '300000.0000'],
        ];

        foreach ($rows as $r) {
            BrokerSummaryDaily::query()->create([
                'instrument_id' => $bbca->id,
                'broker_code' => $r['code'],
                'broker_category' => $r['cat'],
                'trade_date' => '2026-11-26',
                'buy_volume' => $r['bvol'],
                'sell_volume' => $r['svol'],
                'net_volume' => $r['bvol'] - $r['svol'],
                'buy_value' => $r['bval'],
                'sell_value' => $r['sval'],
                'net_value' => bcsub($r['bval'], $r['sval'], 4),
                'currency' => 'IDR',
                'is_demo' => false,
            ]);
        }

        $service = app(BrokerIntelligenceService::class);
        $flow = $service->analyzeInstrumentFlow($bbca, '1D');

        $this->assertNotNull($flow);
        $this->assertSame('strong_accumulation', $flow['summary']['regime']);
        $this->assertSame('1.0000', $flow['summary']['cr3_buy_pct']);
        $this->assertSame('0.8000', $flow['summary']['cr3_sell_pct']);
        $this->assertSame('900000.0000', $flow['summary']['foreign_net_value']);
        $this->assertSame('1000000.0000', $flow['summary']['smart_money_net_value']);
        $this->assertSame('-1000000.0000', $flow['summary']['retail_net_value']);
        $this->assertSame('200000.0000', $flow['summary']['top3_net_imbalance']);

        // Formula-safe CSV export check
        $flow['top_buyers'][0]['broker_name'] = '=HYPERLINK("http://evil","Trap")';
        $csv = $service->exportStockFlowCsv($flow);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(',-300000.0000,', $csv);
    }

    public function test_csv_market_importer_imports_broker_summaries_idempotently_and_rejects_negative_volume(): void
    {
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $tlkm = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'TLKM',
            'name' => 'Telkom Indonesia Tbk',
            'type' => 'equity',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        $source = DataSource::query()->where('code', 'csv_manual')->firstOrFail();
        $importer = app(CsvMarketImporter::class);

        $tmp = tempnam(sys_get_temp_dir(), 'bs_');
        file_put_contents($tmp, implode("\n", [
            'exchange_code,symbol,trade_date,broker_code,buy_volume,sell_volume,buy_value,sell_value',
            'IDX,TLKM,2026-11-26,AK,10000,2000,35000000,7000000',
            'IDX,TLKM,2026-11-26,YP,-50,100,100000,350000',
        ]));

        $run = $importer->importUploadedFile($tmp, 'broker_summaries_daily', $source);
        @unlink($tmp);

        $this->assertSame(2, $run->rows_processed);
        $this->assertSame(1, $run->rows_inserted);
        $this->assertSame(1, $run->rows_skipped);

        $row = BrokerSummaryDaily::query()->where('instrument_id', $tlkm->id)->where('broker_code', 'AK')->firstOrFail();
        $this->assertSame('foreign_institutional', $row->broker_category);
        $this->assertSame(8000, $row->net_volume);
        $this->assertSame('28000000.0000', (string) $row->net_value);
        $this->assertSame('3500.000000', (string) $row->avg_buy_price);
    }
}
