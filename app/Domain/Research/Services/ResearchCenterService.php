<?php

namespace App\Domain\Research\Services;

use App\Models\Instrument;
use App\Models\ResearchNote;
use App\Models\User;
use App\Support\Math\Dec;

final class ResearchCenterService
{
    public const TEMPLATES = ['thesis', 'earnings_review', 'valuation', 'risk_checklist', 'macro'];

    public const CONVICTIONS = ['high', 'medium', 'low', 'neutral'];

    public const STANCES = ['accumulate', 'hold', 'reduce', 'watchlist', 'researching'];

    public const HORIZONS = ['short_term', 'swing', 'long_term'];

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user, array $filters = []): array
    {
        $template = (string) ($filters['template'] ?? 'ALL');
        $stance = (string) ($filters['stance'] ?? 'ALL');
        $conviction = (string) ($filters['conviction'] ?? 'ALL');
        $symbol = strtoupper(trim((string) ($filters['symbol'] ?? '')));
        $q = trim((string) ($filters['q'] ?? ''));

        $query = ResearchNote::query()
            ->with([
                'instrument.exchange:id,code',
                'instrument.latestQuote:instrument_id,close,change_pct',
                'instrument.latestScore:instrument_id,overall_score',
                'instrument.safetyAssessment:instrument_id,risk_category,safeguard_triggered',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($template !== '' && $template !== 'ALL') {
            $query->where('template_type', $template);
        }

        if ($stance !== '' && $stance !== 'ALL') {
            $query->where('stance', $stance);
        }

        if ($conviction !== '' && $conviction !== 'ALL') {
            $query->where('conviction', $conviction);
        }

        if ($symbol !== '') {
            $query->whereHas('instrument', fn ($iq) => $iq->where('symbol', $symbol));
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('thesis_body', 'like', $like);
            });
        }

        return $query->limit(100)->get()->map(fn (ResearchNote $n): array => $this->formatNote($n))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forInstrument(User $user, Instrument $instrument, int $limit = 5): array
    {
        return ResearchNote::query()
            ->with([
                'instrument.exchange:id,code',
                'instrument.latestQuote:instrument_id,close,change_pct',
            ])
            ->where('user_id', $user->id)
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (ResearchNote $n): array => $this->formatNote($n))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNote(ResearchNote $n): array
    {
        $inst = $n->instrument;
        $quote = $inst?->latestQuote;
        $close = $quote ? (string) $quote->close : null;
        $targetPrice = $n->target_price !== null ? (string) $n->target_price : null;
        $targetCcy = $n->target_currency ? strtoupper($n->target_currency) : null;
        $instCcy = $inst ? strtoupper($inst->currency) : null;

        $impliedUpside = null;
        if (
            $targetPrice !== null
            && $close !== null
            && Dec::cmp($close, '0') > 0
            && Dec::cmp($targetPrice, '0') > 0
            && ($targetCcy === null || $instCcy === null || $targetCcy === $instCcy)
        ) {
            $impliedUpside = Dec::pctChange($targetPrice, $close, 6);
        }

        return [
            'id' => $n->id,
            'instrument_id' => $n->instrument_id,
            'title' => $n->title,
            'template_type' => $n->template_type,
            'conviction' => $n->conviction,
            'stance' => $n->stance,
            'target_price' => $targetPrice,
            'target_currency' => $targetCcy,
            'implied_upside_pct' => $impliedUpside,
            'horizon' => $n->horizon,
            'summary' => $n->summary,
            'thesis_body' => $n->thesis_body,
            'catalysts' => $n->catalysts,
            'risks' => $n->risks,
            'invalidation_criteria' => $n->invalidation_criteria,
            'tags' => $n->tags ?? [],
            'is_pinned' => (bool) $n->is_pinned,
            'reviewed_at' => $n->reviewed_at?->toDateString(),
            'updated_at' => $n->updated_at?->toIso8601String(),
            'is_demo' => (bool) $n->is_demo,
            'instrument' => $inst ? [
                'id' => $inst->id,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $inst->exchange?->code ?? '',
                'currency' => $inst->currency,
                'close' => $close,
                'change_pct' => $quote?->change_pct !== null ? (string) $quote->change_pct : null,
                'overall_score' => $inst->latestScore?->overall_score !== null ? (string) $inst->latestScore->overall_score : null,
                'safety_category' => $inst->safetyAssessment?->risk_category ?? null,
                'safeguard_triggered' => (bool) ($inst->safetyAssessment?->safeguard_triggered ?? false),
            ] : null,
        ];
    }
}
