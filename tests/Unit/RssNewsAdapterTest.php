<?php

namespace Tests\Unit;

use App\Domain\Newsroom\Services\RssNewsAdapter;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\NewsArticle;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RssNewsAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class]);
    }

    public function test_parses_rss_xml_offline_tags_tickers_and_is_idempotent(): void
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

        $source = DataSource::query()->firstOrFail();
        $adapter = new RssNewsAdapter();

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>NADI Feed</title>
    <item>
      <title>BBCA Announces Annual Cash Dividend Schedule</title>
      <link>https://example.com/news/bbca-dividend</link>
      <guid>item-bbca-001</guid>
      <pubDate>Fri, 27 Nov 2026 08:00:00 GMT</pubDate>
      <description>Summary regarding BBCA cash distribution.</description>
    </item>
  </channel>
</rss>
XML;

        $first = $adapter->ingestXml($xml, $source, 'Test Wire', 'dividend', 'ID');
        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $first['updated']);

        $article = NewsArticle::query()->where('external_id', 'item-bbca-001')->firstOrFail();
        $this->assertTrue($article->instruments()->where('instruments.id', $bbca->id)->exists());

        // Re-ingesting the same XML updates cleanly without duplicate rows
        $second = $adapter->ingestXml($xml, $source, 'Test Wire', 'dividend', 'ID');
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, NewsArticle::query()->count());
    }

    public function test_ssrf_guard_rejects_http_localhost_and_private_ips(): void
    {
        $adapter = new RssNewsAdapter();

        foreach ([
            'http://example.com/feed.xml',
            'https://127.0.0.1/feed.xml',
            'https://localhost/feed.xml',
            'https://10.0.0.5/internal.xml',
        ] as $unsafe) {
            $threw = false;
            try {
                $adapter->assertSafeFeedUrl($unsafe);
            } catch (InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, "Unsafe URL {$unsafe} should be rejected");
        }

        $adapter->assertSafeFeedUrl('https://feeds.example.com/market.rss');
        $this->assertTrue(true);
    }
}
