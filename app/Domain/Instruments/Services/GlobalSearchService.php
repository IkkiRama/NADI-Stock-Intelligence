<?php

namespace App\Domain\Instruments\Services;

use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\NewsArticle;
use App\Models\RecentView;
use App\Models\ResearchNote;
use App\Models\SearchHistory;
use App\Models\StockIndex;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class GlobalSearchService
{
    /**
     * @return array<string, mixed>
     */
    public function search(string $rawQuery, User $user, ?string $locale = null): array
    {
        $q = trim($rawQuery);
        $lang = $locale ?? $user->settings?->locale ?? 'id';

        if ($q === '') {
            return [
                'query' => '',
                'recent_views' => $this->recentViews($user),
                'recent_searches' => $this->recentSearches($user),
                'stocks' => [],
                'indices' => [],
                'exchanges' => [],
                'articles' => [],
                'research' => [],
                'pages' => $this->searchPages('', $lang),
            ];
        }

        $this->recordSearch($user, $q);

        $stocks = $this->searchStocks($q);
        $indices = $this->searchIndices($q);
        $exchanges = $this->searchExchanges($q);
        $articles = $this->searchArticles($q);
        $research = $this->searchResearch($user, $q);
        $pages = $this->searchPages($q, $lang);

        return [
            'query' => $q,
            'recent_views' => [],
            'recent_searches' => [],
            'stocks' => $stocks,
            'indices' => $indices,
            'exchanges' => $exchanges,
            'articles' => $articles,
            'research' => $research,
            'pages' => $pages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchStocks(string $q): array
    {
        $lower = mb_strtolower($q);
        $upper = strtoupper($q);

        $candidates = Instrument::query()
            ->with(['exchange:id,code', 'latestQuote:instrument_id,close,change_pct,is_demo'])
            ->where('is_active', true)
            ->where(function ($query) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where('symbol', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->limit(40)
            ->get();

        $scored = [];
        foreach ($candidates as $inst) {
            $sym = strtoupper($inst->symbol);
            $nameLower = mb_strtolower($inst->name);

            $rank = match (true) {
                $sym === $upper => 1,
                str_starts_with($sym, $upper) => 2,
                str_starts_with($nameLower, $lower) => 3,
                default => 4,
            };

            $scored[$inst->id] = [
                'rank' => $rank,
                'item' => $this->formatInstrument($inst),
            ];
        }

        // Bounded typo tolerance (designed for universes <= ~10k active rows)
        if (count($scored) < 3 && strlen($lower) >= 3 && strlen($lower) <= 40) {
            $lexicon = $this->activeStockLexicon();
            $fuzzyIds = [];

            foreach ($lexicon as $entry) {
                if (isset($scored[$entry['id']])) {
                    continue;
                }

                $symDist = levenshtein($lower, $entry['symbol_lower']);
                $minDist = $symDist <= 2 ? $symDist : 99;

                if ($minDist > 2) {
                    foreach ($entry['tokens'] as $token) {
                        if (abs(strlen($token) - strlen($lower)) > 2) {
                            continue;
                        }
                        $d = levenshtein($lower, $token);
                        if ($d <= 2 && $d < $minDist) {
                            $minDist = $d;
                        }
                    }
                }

                if ($minDist <= 2) {
                    $fuzzyIds[$entry['id']] = 5 + $minDist;
                }
            }

            if (! empty($fuzzyIds)) {
                $fuzzyModels = Instrument::query()
                    ->with(['exchange:id,code', 'latestQuote:instrument_id,close,change_pct,is_demo'])
                    ->whereIn('id', array_keys($fuzzyIds))
                    ->get();

                foreach ($fuzzyModels as $inst) {
                    $scored[$inst->id] = [
                        'rank' => $fuzzyIds[$inst->id],
                        'item' => $this->formatInstrument($inst),
                    ];
                }
            }
        }

        uasort($scored, function ($a, $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return strcmp($a['item']['symbol'], $b['item']['symbol']);
        });

        return array_slice(array_map(fn ($entry) => $entry['item'], array_values($scored)), 0, 8);
    }

    /**
     * @return list<array{id: int, symbol_lower: string, tokens: list<string>}>
     */
    private function activeStockLexicon(): array
    {
        return Cache::remember('nadi:search:stock_lexicon', now()->addMinutes(30), function (): array {
            return Instrument::query()
                ->where('is_active', true)
                ->select(['id', 'symbol', 'name'])
                ->limit(10000)
                ->get()
                ->map(function (Instrument $inst): array {
                    $rawTokens = preg_split('/[^a-z0-9]+/', strtolower($inst->name)) ?: [];
                    $tokens = array_values(array_unique(array_filter($rawTokens, fn ($t) => strlen($t) >= 3 && strlen($t) <= 40)));

                    return [
                        'id' => $inst->id,
                        'symbol_lower' => strtolower($inst->symbol),
                        'tokens' => $tokens,
                    ];
                })
                ->all();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchIndices(string $q): array
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        return StockIndex::query()
            ->with('latestQuote:stock_index_id,close,change_pct,is_demo')
            ->where('is_active', true)
            ->where(function ($query) use ($like): void {
                $query->where('code', 'like', $like)->orWhere('name', 'like', $like);
            })
            ->limit(5)
            ->get()
            ->map(fn (StockIndex $idx): array => [
                'id' => $idx->id,
                'code' => $idx->code,
                'name' => $idx->name,
                'country_code' => $idx->country_code,
                'currency' => $idx->currency,
                'close' => $idx->latestQuote ? (string) $idx->latestQuote->close : null,
                'change_pct' => $idx->latestQuote?->change_pct !== null ? (string) $idx->latestQuote->change_pct : null,
                'href' => '/markets/indices/'.$idx->code,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchExchanges(string $q): array
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        return Exchange::query()
            ->where('is_active', true)
            ->where(function ($query) use ($like): void {
                $query->where('code', 'like', $like)->orWhere('name', 'like', $like);
            })
            ->limit(5)
            ->get()
            ->map(fn (Exchange $ex): array => [
                'id' => $ex->id,
                'code' => $ex->code,
                'name' => $ex->name,
                'country_code' => $ex->country_code,
                'currency' => $ex->currency,
                'href' => '/stocks?exchange='.$ex->code,
            ])
            ->all();
    }

    /**
     * @return list<array{title: string, href: string, phase: int|null, status: string}>
     */
    private function searchPages(string $q, string $locale): array
    {
        $translations = $this->loadTranslations($locale);
        $pages = [
            ['key' => 'nav.overview', 'href' => '/dashboard', 'phase' => 1, 'status' => 'available'],
            ['key' => 'nav.global_markets', 'href' => '/markets', 'phase' => 1, 'status' => 'available'],
            ['key' => 'nav.heatmap', 'href' => '/heatmap', 'phase' => 2, 'status' => 'available'],
            ['key' => 'nav.stock_explorer', 'href' => '/stocks', 'phase' => 1, 'status' => 'available'],
            ['key' => 'nav.screener', 'href' => '/screener', 'phase' => 2, 'status' => 'available'],
            ['key' => 'nav.opportunities', 'href' => '/opportunities', 'phase' => 3, 'status' => 'available'],
            ['key' => 'nav.stock_safety', 'href' => '/stock-safety', 'phase' => 3, 'status' => 'available'],
            ['key' => 'nav.entry_exit', 'href' => '/entry-exit', 'phase' => 3, 'status' => 'available'],
            ['key' => 'nav.strategy_lab', 'href' => '/strategy-lab', 'phase' => 11, 'status' => 'available'],
            ['key' => 'nav.portfolio', 'href' => '/portfolio', 'phase' => 4, 'status' => 'available'],
            ['key' => 'nav.portfolio_analytics', 'href' => '/portfolio-analytics', 'phase' => 6, 'status' => 'available'],
            ['key' => 'nav.dividends', 'href' => '/dividends', 'phase' => 8, 'status' => 'available'],
            ['key' => 'nav.risk_rebalancing', 'href' => '/risk-rebalancing', 'phase' => 7, 'status' => 'available'],
            ['key' => 'nav.reports', 'href' => '/reports', 'phase' => 9, 'status' => 'available'],
            ['key' => 'nav.newsroom', 'href' => '/newsroom', 'phase' => 8, 'status' => 'available'],
            ['key' => 'nav.broker_intelligence', 'href' => '/broker-intelligence', 'phase' => 10, 'status' => 'available'],
            ['key' => 'nav.research_center', 'href' => '/research-center', 'phase' => 8, 'status' => 'available'],
            ['key' => 'nav.alerts_calendar', 'href' => '/alerts-calendar', 'phase' => 3, 'status' => 'available'],
            ['key' => 'nav.calculators', 'href' => '/calculators', 'phase' => 4, 'status' => 'available'],
            ['key' => 'nav.settings', 'href' => '/settings/preferences', 'phase' => 1, 'status' => 'available'],
        ];

        foreach (config('nadi_modules', []) as $mod) {
            $pages[] = [
                'key' => $mod['title_key'],
                'href' => '/segera/'.$mod['slug'],
                'phase' => $mod['phase'],
                'status' => 'planned',
            ];
        }

        $lower = mb_strtolower($q);
        $matched = [];
        foreach ($pages as $page) {
            $title = $translations[$page['key']] ?? $page['key'];
            if ($lower === '' || str_contains(mb_strtolower($title), $lower) || str_contains(mb_strtolower($page['href']), $lower)) {
                $matched[] = [
                    'title' => $title,
                    'href' => $page['href'],
                    'phase' => $page['phase'],
                    'status' => $page['status'],
                ];
            }
        }

        return array_slice($matched, 0, 6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchArticles(string $q): array
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        return NewsArticle::query()
            ->where(function ($sub) use ($like): void {
                $sub->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('source_name', 'like', $like);
            })
            ->orderByDesc('published_at')
            ->limit(5)
            ->get(['id', 'slug', 'title', 'source_name', 'category', 'is_demo'])
            ->map(fn (NewsArticle $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'source_name' => $a->source_name,
                'category' => $a->category,
                'is_demo' => (bool) $a->is_demo,
                'href' => '/newsroom?article='.$a->slug,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchResearch(User $user, string $q): array
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        return ResearchNote::query()
            ->with('instrument:id,symbol')
            ->where('user_id', $user->id)
            ->where(function ($sub) use ($like): void {
                $sub->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('thesis_body', 'like', $like)
                    ->orWhereHas('instrument', fn ($iq) => $iq->where('symbol', 'like', $like));
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'instrument_id', 'title', 'stance', 'template_type', 'is_demo'])
            ->map(fn (ResearchNote $n): array => [
                'id' => $n->id,
                'title' => $n->title,
                'symbol' => $n->instrument?->symbol,
                'stance' => $n->stance,
                'is_demo' => (bool) $n->is_demo,
                'href' => '/research-center?note='.$n->id,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentViews(User $user): array
    {
        $views = RecentView::query()
            ->where('user_id', $user->id)
            ->orderByDesc('viewed_at')
            ->limit(6)
            ->get();

        $results = [];
        foreach ($views as $view) {
            if ($view->viewable_type === Instrument::class) {
                $inst = Instrument::query()->with('exchange:id,code')->find($view->viewable_id);
                if ($inst) {
                    $results[] = [
                        'kind' => 'stock',
                        'title' => $inst->symbol.' · '.$inst->exchange->code,
                        'subtitle' => $inst->name,
                        'href' => '/stocks/'.$inst->exchange->code.'/'.$inst->symbol,
                    ];
                }
            } elseif ($view->viewable_type === StockIndex::class) {
                $idx = StockIndex::query()->find($view->viewable_id);
                if ($idx) {
                    $results[] = [
                        'kind' => 'index',
                        'title' => $idx->code,
                        'subtitle' => $idx->name,
                        'href' => '/markets/indices/'.$idx->code,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function recentSearches(User $user): array
    {
        return SearchHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->pluck('query')
            ->unique()
            ->values()
            ->all();
    }

    private function recordSearch(User $user, string $query): void
    {
        $trimmed = mb_substr($query, 0, 120);

        SearchHistory::query()->create([
            'user_id' => $user->id,
            'query' => $trimmed,
            'created_at' => now('UTC'),
        ]);

        // Prune to keep the most recent 20 per user
        $keepIds = SearchHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('id');

        if ($keepIds->count() === 20) {
            SearchHistory::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInstrument(Instrument $inst): array
    {
        return [
            'id' => $inst->id,
            'symbol' => $inst->symbol,
            'name' => $inst->name,
            'exchange_code' => $inst->exchange->code,
            'currency' => $inst->currency,
            'sector' => $inst->sector,
            'close' => $inst->latestQuote ? (string) $inst->latestQuote->close : null,
            'change_pct' => $inst->latestQuote?->change_pct !== null ? (string) $inst->latestQuote->change_pct : null,
            'href' => '/stocks/'.$inst->exchange->code.'/'.$inst->symbol,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        $safeLocale = in_array($locale, config('nadi.locales', ['id', 'en']), true) ? $locale : 'id';
        $path = lang_path($safeLocale.'.json');
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
