<?php

namespace App\Console\Commands;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use Illuminate\Console\Command;

class RefreshLatestQuotesCommand extends Command
{
    protected $signature = 'nadi:refresh-quotes';

    protected $description = 'Refresh precomputed latest instrument and index quotes';

    public function handle(RefreshLatestQuotes $action): int
    {
        $counts = $action->execute();
        $this->info("Refreshed quotes: {$counts['instruments']} instruments, {$counts['indices']} indices.");

        return self::SUCCESS;
    }
}
