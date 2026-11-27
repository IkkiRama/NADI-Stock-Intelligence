<?php

namespace App\Http\Controllers;

use App\Domain\Newsroom\Services\NewsroomService;
use App\Domain\Newsroom\Services\RssNewsAdapter;
use App\Models\DataSource;
use App\Models\Instrument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NewsroomController extends Controller
{
    public function index(Request $request, NewsroomService $newsroom): Response
    {
        $user = $request->user();
        $filters = [
            'scope' => (string) $request->query('scope', 'all'),
            'category' => (string) $request->query('category', 'ALL'),
            'country' => strtoupper(trim((string) $request->query('country', 'ALL'))),
            'impact' => (string) $request->query('impact', 'ALL'),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'q' => trim((string) $request->query('q', '')),
            'article' => trim((string) $request->query('article', '')),
        ];

        $articles = $newsroom->paginate($user, $filters, 15);
        $instruments = Instrument::query()
            ->with('exchange:id,code')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'exchange_id', 'symbol', 'name']);

        return Inertia::render('newsroom/index', [
            'articles' => $articles,
            'filters' => $filters,
            'categories' => RssNewsAdapter::VALID_CATEGORIES,
            'impacts' => RssNewsAdapter::VALID_IMPACTS,
            'countries' => ['ID', 'US', 'SG', 'CN', 'HK'],
            'instruments' => $instruments->map(fn (Instrument $i): array => [
                'id' => $i->id,
                'symbol' => $i->symbol,
                'name' => $i->name,
                'exchange_code' => $i->exchange?->code ?? '',
            ])->values()->all(),
            'canPublish' => $user->can('manage-imports'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage-imports'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:240'],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'body' => ['nullable', 'string', 'max:10000'],
            'source_name' => ['required', 'string', 'max:100'],
            'url' => ['nullable', 'url', 'max:500'],
            'category' => ['required', Rule::in(RssNewsAdapter::VALID_CATEGORIES)],
            'country_code' => ['nullable', 'string', 'max:8'],
            'impact_tag' => ['required', Rule::in(RssNewsAdapter::VALID_IMPACTS)],
            'instrument_ids' => ['nullable', 'array', 'max:10'],
            'instrument_ids.*' => ['integer', 'exists:instruments,id'],
        ]);

        $source = DataSource::query()->where('code', 'manual_csv')->first()
            ?? DataSource::query()->firstOrFail();

        $baseSlug = Str::slug($validated['title']);
        $slug = Str::limit($baseSlug ?: 'editorial-brief', 160, '').'-'.substr(sha1(now('UTC')->toIso8601String().$validated['title']), 0, 8);

        $article = \App\Models\NewsArticle::query()->create([
            'data_source_id' => $source->id,
            'source_name' => $validated['source_name'],
            'title' => $validated['title'],
            'slug' => $slug,
            'summary' => $validated['summary'],
            'body' => $validated['body'] ?? null,
            'url' => $validated['url'] ?? null,
            'category' => $validated['category'],
            'country_code' => $validated['country_code'] ? strtoupper($validated['country_code']) : null,
            'language' => $request->user()->settings?->locale ?? 'id',
            'impact_tag' => $validated['impact_tag'],
            'published_at' => now('UTC'),
            'is_demo' => false,
        ]);

        if (! empty($validated['instrument_ids'])) {
            $article->instruments()->sync($validated['instrument_ids']);
        }

        return back()->with('status', 'news-article-published');
    }
}
