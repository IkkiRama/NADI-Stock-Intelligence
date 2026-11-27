<?php

namespace App\Domain\MarketData\Contracts;

use App\Models\Instrument;
use App\Models\StockIndex;
use Carbon\CarbonInterface;

interface MarketDataProvider
{
    public function code(): string;

    public function supports(string $dataset, string $exchangeCode): bool;

    /**
     * Yield daily bar arrays with string decimal values:
     * [
     *   'trade_date' => 'YYYY-MM-DD',
     *   'open' => string,
     *   'high' => string,
     *   'low' => string,
     *   'close' => string,
     *   'volume' => int,
     *   'value_traded' => string|null,
     * ]
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function dailyBars(Instrument|StockIndex $entity, CarbonInterface $from, CarbonInterface $to): iterable;
}
