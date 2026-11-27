<?php

namespace Tests\Unit;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentQuoteLatest;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshLatestQuotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_exact_change_and_handles_single_bar(): void
    {
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class]);

        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $source = DataSource::query()->where('code', 'demo')->firstOrFail();

        $instTwoBars = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'TEST2',
            'name' => 'Two Bars Corp',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        InstrumentPriceDaily::query()->create([
            'instrument_id' => $instTwoBars->id,
            'trade_date' => '2026-11-24',
            'open' => '1000.000000',
            'high' => '1020.000000',
            'low' => '990.000000',
            'close' => '1000.000000',
            'volume' => 10000,
            'data_source_id' => $source->id,
            'is_demo' => true,
        ]);

        InstrumentPriceDaily::query()->create([
            'instrument_id' => $instTwoBars->id,
            'trade_date' => '2026-11-25',
            'open' => '1010.000000',
            'high' => '1120.000000',
            'low' => '1000.000000',
            'close' => '1100.000000',
            'volume' => 15000,
            'data_source_id' => $source->id,
            'is_demo' => true,
        ]);

        $instOneBar = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'TEST1',
            'name' => 'One Bar Corp',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        InstrumentPriceDaily::query()->create([
            'instrument_id' => $instOneBar->id,
            'trade_date' => '2026-11-25',
            'open' => '500.000000',
            'high' => '510.000000',
            'low' => '495.000000',
            'close' => '505.000000',
            'volume' => 5000,
            'data_source_id' => $source->id,
            'is_demo' => true,
        ]);

        $action = new RefreshLatestQuotes();
        $counts = $action->execute();
        $this->assertSame(2, $counts['instruments']);

        $q2 = InstrumentQuoteLatest::query()->findOrFail($instTwoBars->id);
        $this->assertSame('1100.000000', $q2->close);
        $this->assertSame('1000.000000', $q2->prev_close);
        $this->assertSame('100.000000', $q2->change);
        $this->assertSame('0.10000000', $q2->change_pct);

        $q1 = InstrumentQuoteLatest::query()->findOrFail($instOneBar->id);
        $this->assertSame('505.000000', $q1->close);
        $this->assertNull($q1->prev_close);
        $this->assertNull($q1->change);
        $this->assertNull($q1->change_pct);
    }
}
