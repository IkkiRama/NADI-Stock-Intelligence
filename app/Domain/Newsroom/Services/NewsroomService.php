<?php

namespace App\Domain\Newsroom\Services;

use App\Models\Instrument;
use App\Models\NewsArticle;
use App\Models\PortfolioHolding;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class NewsroomService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(User $user, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $scope = (string) ($filters['scope'] ?? 'all');
        $category = (string) ($filters['category'] ?? 'ALL');
        $country = strtoupper(trim((string) ($filters['country'] ?? 'ALL')));
        $impact = (string) ($filters['impact'] ?? 'ALL');
        $symbol = strtoupper(trim((string) ($filters['symbol'] ?? '')));
        $q = trim((string) ($filters['q'] ?? ''));

        $query = NewsArticle::query()
            ->with([
                'dataSource:id,code,name,is_demo',
                'instruments' => fn ($iq) => $iq->with(['exchange:id,code', 'latestQuote:instrument_id,close,change_pct']),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($scope === 'portfolio') {
            $heldInstIds = PortfolioHolding::query()
                ->where('user_id', $user->id)
                ->where('quantity', '>', 0)
                ->pluck('instrument_id')
                ->unique()
                ->values()
                ->all();

            $query->whereHas('instruments', fn ($iq) => $iq->whereIn('instruments.id', empty($heldInstIds) ? [-1] : $heldInstIds));
        } elseif ($scope === 'watchlist') {
            $watch = $user->watchlists()->where('is_default', true)->first();
            $watchInstIds = $watch ? $watch->items()->pluck('instrument_id')->all() : [];
            $query->whereHas('instruments', fn ($iq) => $iq->whereIn('instruments.id', empty($watchInstIds) ? [-1] : $watchInstIds));
        }

        if ($category !== '' && $category !== 'ALL') {
            $query->where('category', $category);
        }

        if ($country !== '' && $country !== 'ALL') {
            $query->where('country_code', $country);
        }

        if ($impact !== '' && $impact !== 'ALL') {
            $query->where('impact_tag', $impact);
        }

        if ($symbol !== '') {
            $query->whereHas('instruments', fn ($iq) => $iq->where('instruments.symbol', $symbol));
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('source_name', 'like', $like);
            });
        }

        return $query->paginate($perPage)->withQueryString()->through(fn (NewsArticle $a): array => $this->formatArticle($a, true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestForDashboard(User $user, int $limit = 5): array
    {
        return NewsArticle::query()
            ->with(['instruments.exchange:id,code'])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (NewsArticle $a): array => $this->formatArticle($a, false))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forInstrument(Instrument $instrument, int $limit = 5): array
    {
        return $instrument->newsArticles()
            ->with(['instruments.exchange:id,code'])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (NewsArticle $a): array => $this->formatArticle($a, true))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatArticle(NewsArticle $a, bool $includeBody = false): array
    {
        return [
            'id' => $a->id,
            'slug' => $a->slug,
            'title' => $a->title,
            'summary' => $a->summary,
            'body' => $includeBody ? $a->body : null,
            'url' => $a->url,
            'source_name' => $a->source_name,
            'category' => $a->category,
            'country_code' => $a->country_code,
            'language' => $a->language,
            'impact_tag' => $a->impact_tag,
            'published_at' => $a->published_at?->toIso8601String(),
            'is_demo' => (bool) $a->is_demo,
            'instruments' => $a->instruments->map(fn (Instrument $inst): array => [
                'id' => $inst->id,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $inst->exchange?->code ?? '',
                'currency' => $inst->currency,
                'close' => $inst->latestQuote ? (string) $inst->latestQuote->close : null,
                'change_pct' => $inst->latestQuote?->change_pct !== null ? (string) $inst->latestQuote->change_pct : null,
            ])->values()->all(),
        ];
    }
}
