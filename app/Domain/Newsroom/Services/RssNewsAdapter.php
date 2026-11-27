<?php

namespace App\Domain\Newsroom\Services;

use App\Models\DataSource;
use App\Models\Instrument;
use App\Models\NewsArticle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared-hosting-compatible RSS/Atom News Ingestion Adapter.
 *
 * Uses built-in PHP SimpleXML (no external dependencies) and enforces HTTPS-only,
 * non-private-IP URL validation to prevent SSRF attacks when syncing external feeds.
 */
final class RssNewsAdapter
{
    public const VALID_CATEGORIES = ['earnings', 'dividend', 'macro', 'corporate_action', 'regulation', 'market_recap'];

    public const VALID_IMPACTS = ['positive', 'neutral', 'caution'];

    /**
     * Validate remote RSS/Atom feed URL against SSRF vectors.
     */
    public function assertSafeFeedUrl(string $url): void
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('News feed URL must use HTTPS.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new InvalidArgumentException('News feed host cannot target local or loopback addresses.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('News feed URL cannot target private or reserved IP ranges.');
            }
        }
    }

    /**
     * Fetch and ingest items from a configured HTTPS RSS/Atom feed.
     *
     * @return array{imported: int, updated: int}
     */
    public function syncFromUrl(DataSource $source, string $url, string $defaultCategory = 'market_recap', ?string $countryCode = null): array
    {
        $this->assertSafeFeedUrl($url);

        $response = Http::timeout(10)
            ->withHeaders(['User-Agent' => 'NADI-NewsSync/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Feed returned HTTP {$response->status()}.");
        }

        return $this->ingestXml($response->body(), $source, $source->name, $defaultCategory, $countryCode);
    }

    /**
     * Parse raw RSS 2.0 or Atom XML offline and persist tagged NewsArticle records.
     *
     * @return array{imported: int, updated: int}
     */
    public function ingestXml(
        string $xmlContent,
        DataSource $source,
        string $sourceName,
        string $defaultCategory = 'market_recap',
        ?string $countryCode = null,
    ): array {
        $items = $this->parseXml($xmlContent, $sourceName, $defaultCategory, $countryCode);
        if (empty($items)) {
            return ['imported' => 0, 'updated' => 0];
        }

        $symbolMap = Instrument::query()
            ->where('is_active', true)
            ->get(['id', 'symbol'])
            ->keyBy(fn (Instrument $i): string => strtoupper($i->symbol));

        $imported = 0;
        $updated = 0;

        foreach ($items as $item) {
            $slug = Str::limit(Str::slug($source->code.'-'.$item['external_id'].'-'.$item['title']), 175, '');
            if ($slug === '') {
                $slug = 'news-'.substr(sha1($item['title'].$item['published_at']), 0, 16);
            }

            $existing = NewsArticle::query()
                ->where(function ($q) use ($source, $item, $slug): void {
                    $q->where('slug', $slug)
                        ->orWhere(function ($sub) use ($source, $item): void {
                            $sub->where('data_source_id', $source->id)
                                ->where('external_id', $item['external_id']);
                        });
                })
                ->first();

            $payload = [
                'data_source_id' => $source->id,
                'external_id' => $item['external_id'],
                'source_name' => $item['source_name'],
                'title' => $item['title'],
                'slug' => $existing?->slug ?? $slug,
                'summary' => $item['summary'],
                'body' => $item['body'],
                'url' => $item['url'],
                'category' => $item['category'],
                'country_code' => $item['country_code'],
                'language' => $item['language'],
                'impact_tag' => $item['impact_tag'],
                'published_at' => $item['published_at'],
                'is_demo' => (bool) $source->is_demo,
            ];

            if ($existing) {
                $existing->update($payload);
                $article = $existing;
                $updated++;
            } else {
                $article = NewsArticle::query()->create($payload);
                $imported++;
            }

            $matchedIds = $this->detectMentionedInstrumentIds(
                $item['title'].' '.$item['summary'],
                $symbolMap,
            );
            if (! empty($matchedIds)) {
                $article->instruments()->syncWithoutDetaching($matchedIds);
            }
        }

        return ['imported' => $imported, 'updated' => $updated];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseXml(
        string $xmlContent,
        string $sourceName,
        string $defaultCategory = 'market_recap',
        ?string $countryCode = null,
    ): array {
        $trimmed = trim($xmlContent);
        if ($trimmed === '') {
            return [];
        }

        $prevUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($trimmed, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if ($xml === false) {
            return [];
        }

        $category = in_array($defaultCategory, self::VALID_CATEGORIES, true) ? $defaultCategory : 'market_recap';
        $items = [];

        // RSS 2.0 (<rss><channel><item>)
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $node) {
                $title = trim(strip_tags((string) $node->title));
                if ($title === '') {
                    continue;
                }
                $link = $this->sanitizeHttpUrl((string) $node->link);
                $desc = trim(html_entity_decode(strip_tags((string) $node->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $guid = trim((string) ($node->guid ?: $link ?: sha1($title)));
                $pubRaw = trim((string) $node->pubDate);
                $pubAt = $this->parseDate($pubRaw);

                $items[] = [
                    'external_id' => Str::limit($guid, 118, ''),
                    'source_name' => Str::limit($sourceName, 98, ''),
                    'title' => Str::limit($title, 238, ''),
                    'summary' => Str::limit($desc !== '' ? $desc : $title, 600, ''),
                    'body' => $desc !== '' ? $desc : null,
                    'url' => $link,
                    'category' => $category,
                    'country_code' => $countryCode,
                    'language' => 'en',
                    'impact_tag' => 'neutral',
                    'published_at' => $pubAt,
                ];
            }
        } elseif (isset($xml->entry)) {
            // Atom (<feed><entry>)
            foreach ($xml->entry as $node) {
                $title = trim(strip_tags((string) $node->title));
                if ($title === '') {
                    continue;
                }
                $href = '';
                foreach ($node->link as $lnk) {
                    $href = (string) ($lnk['href'] ?? '');
                    if ($href !== '') {
                        break;
                    }
                }
                $link = $this->sanitizeHttpUrl($href);
                $desc = trim(html_entity_decode(strip_tags((string) ($node->summary ?: $node->content)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $guid = trim((string) ($node->id ?: $link ?: sha1($title)));
                $pubRaw = trim((string) ($node->updated ?: $node->published));

                $items[] = [
                    'external_id' => Str::limit($guid, 118, ''),
                    'source_name' => Str::limit($sourceName, 98, ''),
                    'title' => Str::limit($title, 238, ''),
                    'summary' => Str::limit($desc !== '' ? $desc : $title, 600, ''),
                    'body' => $desc !== '' ? $desc : null,
                    'url' => $link,
                    'category' => $category,
                    'country_code' => $countryCode,
                    'language' => 'en',
                    'impact_tag' => 'neutral',
                    'published_at' => $this->parseDate($pubRaw),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Instrument>  $symbolMap
     * @return list<int>
     */
    private function detectMentionedInstrumentIds(string $text, $symbolMap): array
    {
        if (preg_match_all('/\b([A-Z0-9]{2,6})\b/', strtoupper($text), $matches) !== 1 && empty($matches[1])) {
            return [];
        }

        $ids = [];
        foreach (array_unique($matches[1] ?? []) as $token) {
            if (isset($symbolMap[$token])) {
                $ids[] = (int) $symbolMap[$token]->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function sanitizeHttpUrl(string $raw): ?string
    {
        $url = trim($raw);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        return Str::limit($url, 490, '');
    }

    private function parseDate(string $raw): CarbonImmutable
    {
        if ($raw !== '') {
            try {
                return CarbonImmutable::parse($raw, 'UTC');
            } catch (\Throwable) {
                // Fall back to current UTC time when RSS pubDate is malformed
            }
        }

        return CarbonImmutable::now('UTC');
    }
}
