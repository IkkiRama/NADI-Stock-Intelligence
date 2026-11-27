<?php

namespace App\Console\Commands;

use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use Illuminate\Console\Command;

class RefreshAnalyticsCommand extends Command
{
    protected $signature = 'nadi:refresh-analytics';

    protected $description = 'Recompute latest quotes, fundamental ratios, technical indicators, and stock scores';

    public function handle(RefreshMarketIntelligence $action): int
    {
        $counts = $action->execute();
        $this->info(
            "Refreshed analytics: {$counts['fundamentals']} fundamentals, {$counts['technicals']} technicals, "
            ."{$counts['scores']} scores, {$counts['safety']} safety assessments, {$counts['signals']} signals, "
            ."{$counts['alerts_triggered']} alert events."
        );

        return self::SUCCESS;
    }
}
