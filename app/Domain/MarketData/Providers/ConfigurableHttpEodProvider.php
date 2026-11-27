<?php

namespace App\Domain\MarketData\Providers;

use App\Domain\MarketData\Contracts\MarketDataProvider;
use App\Models\Instrument;
use App\Models\StockIndex;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Configurable external EOD HTTP provider adapter.
 * Requires explicit NADI_EOD_API_URL and NADI_EOD_API_KEY configuration.
 * Never fabricates market data when unconfigured.
 */
final class ConfigurableHttpEodProvider implements MarketDataProvider
{
    public function code(): string
    {
        return 'http_eod';
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.market_data_eod.base_url'))
            && ! empty(config('services.market_data_eod.api_key'));
    }

    public function supports(string $dataset, string $exchangeCode): bool
    {
        return $this->isConfigured() && in_array($dataset, ['prices_daily', 'index_values_daily'], true);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function dailyBars(Instrument|StockIndex $entity, CarbonInterface $from, CarbonInterface $to): iterable
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('External EOD provider is not configured (set NADI_EOD_API_URL and NADI_EOD_API_KEY).');
        }

        throw new RuntimeException('External HTTP EOD feed mapping must be verified against the chosen licensed vendor endpoint.');
    }
}
