<?php

namespace Tests\Unit;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use App\Domain\MarketData\Services\CsvMarketImporter;
use App\Domain\MarketData\Services\MarketClockService;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\MarketHoliday;
use Carbon\CarbonImmutable;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvMarketImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_prices_and_holidays_idempotently_and_rejects_invalid_ohlc(): void
    {
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class]);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $csvSource = DataSource::query()->where('code', 'csv_manual')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'CSVT',
            'name' => 'CSV Test Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        $importer = new CsvMarketImporter(new RefreshLatestQuotes());

        // 1. Prices CSV: 1 valid row + 1 invalid OHLC row (high < close)
        $pricePath = tempnam(sys_get_temp_dir(), 'nadi_px_');
        file_put_contents($pricePath, implode("\n", [
            'exchange_code,symbol,trade_date,open,high,low,close,volume',
            'IDX,CSVT,2026-11-25,1000,1050,990,1040,250000',
            'IDX,CSVT,2026-11-26,1000,900,990,1040,250000',
        ]));

        $run1 = $importer->importUploadedFile($pricePath, 'prices_daily', $csvSource);
        $this->assertSame('completed', $run1->status);
        $this->assertSame(2, $run1->rows_processed);
        $this->assertSame(1, $run1->rows_inserted);
        $this->assertSame(1, $run1->rows_skipped);
        $this->assertSame(1, InstrumentPriceDaily::query()->where('instrument_id', $inst->id)->count());

        // Re-import is idempotent
        $run2 = $importer->importUploadedFile($pricePath, 'prices_daily', $csvSource);
        $this->assertSame(1, $run2->rows_inserted);
        $this->assertSame(1, InstrumentPriceDaily::query()->where('instrument_id', $inst->id)->count());
        @unlink($pricePath);

        // 2. Market holidays CSV with formula-injection prefix gets sanitized and flips holidays_loaded=true
        $holPath = tempnam(sys_get_temp_dir(), 'nadi_hol_');
        file_put_contents($holPath, implode("\n", [
            'exchange_code,date,name,is_half_day',
            'IDX,2026-12-25,=CMD|Christmas,0',
        ]));

        $holRun = $importer->importUploadedFile($holPath, 'market_holidays', $csvSource);
        $this->assertSame('completed', $holRun->status);
        $this->assertSame(1, $holRun->rows_inserted);
        @unlink($holPath);

        $holiday = MarketHoliday::query()->where('exchange_id', $idx->id)->firstOrFail();
        $this->assertSame("'=CMD|Christmas", $holiday->name);

        $clock = new MarketClockService();
        $status = $clock->status($idx->fresh('holidays'), CarbonImmutable::create(2026, 12, 25, 10, 0, 0, 'Asia/Jakarta'));
        $this->assertSame('holiday', $status['state']);
        $this->assertTrue($status['holidays_loaded']);
    }
}
