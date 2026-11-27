<?php

namespace App\Console\Commands;

use App\Domain\Newsroom\Services\RssNewsAdapter;
use App\Models\DataSource;
use Illuminate\Console\Command;
use Throwable;

class SyncNewsFeeds extends Command
{
    protected $signature = 'nadi:sync-news';

    protected $description = 'Sync configured HTTPS RSS/Atom news feeds into Financial Newsroom.';

    public function handle(RssNewsAdapter $adapter): int
    {
        $sources = DataSource::query()
            ->where('is_enabled', true)
            ->whereIn('type', ['rss', 'news_rss', 'rss_feed'])
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No enabled RSS/Atom news data sources configured.');

            return self::SUCCESS;
        }

        $totalImported = 0;
        $totalUpdated = 0;

        foreach ($sources as $source) {
            $feeds = $source->config['feeds'] ?? [];
            if (is_string($source->config['feed_url'] ?? null)) {
                $feeds[] = [
                    'url' => $source->config['feed_url'],
                    'category' => $source->config['category'] ?? 'market_recap',
                    'country_code' => $source->config['country_code'] ?? null,
                ];
            }

            foreach ($feeds as $feed) {
                $url = (string) ($feed['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                try {
                    $res = $adapter->syncFromUrl(
                        $source,
                        $url,
                        (string) ($feed['category'] ?? 'market_recap'),
                        isset($feed['country_code']) ? (string) $feed['country_code'] : null,
                    );
                    $totalImported += $res['imported'];
                    $totalUpdated += $res['updated'];
                } catch (Throwable $e) {
                    $this->warn("Feed sync skipped [{$source->code}]: {$e->getMessage()}");
                    $source->forceFill(['last_failure_at' => now('UTC')])->save();
                }
            }

            $source->forceFill(['last_success_at' => now('UTC')])->save();
        }

        $this->info("Synced news feeds: {$totalImported} imported, {$totalUpdated} updated.");

        return self::SUCCESS;
    }
}
