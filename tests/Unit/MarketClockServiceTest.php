<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\MarketClockService;
use App\Models\Exchange;
use App\Models\MarketHoliday;
use Carbon\CarbonImmutable;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketClockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExchangeSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_idx_session_states_and_missing_holidays_flag(): void
    {
        $clock = new MarketClockService();
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        // Monday 10:00 WIB -> open
        $monOpen = CarbonImmutable::create(2026, 11, 23, 10, 0, 0, 'Asia/Jakarta');
        $resOpen = $clock->status($idx, $monOpen);
        $this->assertSame('open', $resOpen['state']);
        $this->assertFalse($resOpen['holidays_loaded']);

        // Monday 12:30 WIB -> break
        $monBreak = CarbonImmutable::create(2026, 11, 23, 12, 30, 0, 'Asia/Jakarta');
        $this->assertSame('break', $clock->status($idx, $monBreak)['state']);

        // Friday 11:45 WIB -> break (Fri morning ends 11:30, afternoon starts 14:00)
        $friBreak = CarbonImmutable::create(2026, 11, 27, 11, 45, 0, 'Asia/Jakarta');
        $this->assertSame('break', $clock->status($idx, $friBreak)['state']);

        // Saturday -> weekend
        $sat = CarbonImmutable::create(2026, 11, 28, 10, 0, 0, 'Asia/Jakarta');
        $this->assertSame('weekend', $clock->status($idx, $sat)['state']);

        // Seeded holiday -> holiday and holidays_loaded true
        MarketHoliday::query()->create([
            'exchange_id' => $idx->id,
            'date' => '2026-11-24',
            'name' => 'Test Exchange Holiday',
            'is_half_day' => false,
        ]);
        $idx->unsetRelation('holidays');

        $holidayTime = CarbonImmutable::create(2026, 11, 24, 10, 0, 0, 'Asia/Jakarta');
        $resHoliday = $clock->status($idx, $holidayTime);
        $this->assertSame('holiday', $resHoliday['state']);
        $this->assertSame('Test Exchange Holiday', $resHoliday['holiday_name']);
        $this->assertTrue($resHoliday['holidays_loaded']);
    }

    public function test_nyse_and_hkex_session_states(): void
    {
        $clock = new MarketClockService();
        $nyse = Exchange::query()->where('code', 'NYSE')->firstOrFail();
        $hkex = Exchange::query()->where('code', 'HKEX')->firstOrFail();

        $nysePre = CarbonImmutable::create(2026, 11, 23, 9, 29, 0, 'America/New_York');
        $this->assertSame('closed', $clock->status($nyse, $nysePre)['state']);

        $hkBreak = CarbonImmutable::create(2026, 11, 23, 12, 30, 0, 'Asia/Hong_Kong');
        $this->assertSame('break', $clock->status($hkex, $hkBreak)['state']);
    }
}
