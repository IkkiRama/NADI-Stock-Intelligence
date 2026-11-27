<?php

namespace App\Http\Controllers;

use App\Domain\MarketData\Services\MarketOverviewService;
use App\Domain\Newsroom\Services\NewsroomService;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Models\AlertEvent;
use App\Models\CorporateAction;
use App\Models\Exchange;
use App\Models\StockSafetyAssessment;
use App\Models\TradingSignal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        MarketOverviewService $overview,
        PortfolioValuationService $valuation,
        NewsroomService $newsroom,
    ): Response {
        $user = $request->user();
        $user->loadMissing('settings');

        $allowedExchanges = array_merge(['ALL'], config('nadi.exchanges', []));
        $requestedExchange = strtoupper((string) $request->query('exchange', $user->settings?->default_market ?? 'IDX'));
        $selectedExchange = in_array($requestedExchange, $allowedExchanges, true) ? $requestedExchange : 'IDX';

        $watchlist = $user->watchlists()->where('is_default', true)->first();
        $watchlistItems = [];
        if ($watchlist) {
            $watchlistItems = $watchlist->items()
                ->with(['instrument.exchange:id,code,currency', 'instrument.latestQuote'])
                ->get()
                ->filter(fn ($item) => $item->instrument !== null)
                ->map(function ($item): array {
                    $inst = $item->instrument;
                    $quote = $inst->latestQuote;

                    return [
                        'id' => $inst->id,
                        'symbol' => $inst->symbol,
                        'name' => $inst->name,
                        'exchange_code' => $inst->exchange->code,
                        'currency' => $inst->currency,
                        'close' => $quote ? (string) $quote->close : null,
                        'change' => $quote?->change !== null ? (string) $quote->change : null,
                        'change_pct' => $quote?->change_pct !== null ? (string) $quote->change_pct : null,
                        'as_of' => $quote?->trade_date?->toDateString(),
                        'is_demo' => (bool) ($quote?->is_demo ?? false),
                    ];
                })
                ->values()
                ->all();
        }

        $safetyWarnings = StockSafetyAssessment::query()
            ->with('instrument.exchange:id,code')
            ->where(function ($q): void {
                $q->where('safeguard_triggered', true)
                    ->orWhereIn('risk_category', ['high', 'elevated']);
            })
            ->orderByDesc('safeguard_triggered')
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get()
            ->filter(fn ($s) => $s->instrument !== null)
            ->map(fn ($s): array => [
                'id' => $s->instrument->id,
                'symbol' => $s->instrument->symbol,
                'name' => $s->instrument->name,
                'exchange_code' => $s->instrument->exchange->code,
                'risk_score' => $s->risk_score !== null ? (string) $s->risk_score : null,
                'risk_category' => $s->risk_category,
                'safeguard_triggered' => (bool) $s->safeguard_triggered,
                'rule_count' => is_array($s->triggered_rules) ? count($s->triggered_rules) : 0,
                'is_demo' => (bool) $s->is_demo,
            ])
            ->values()
            ->all();

        $recentSignals = TradingSignal::query()
            ->with('instrument.exchange:id,code')
            ->where('horizon', 'swing')
            ->where('is_latest', true)
            ->where('safeguard_suppressed', false)
            ->whereIn('stance', ['actionable_setup', 'watchlist_setup'])
            ->orderByRaw("CASE WHEN stance = 'actionable_setup' THEN 0 ELSE 1 END")
            ->orderByDesc('net_rr')
            ->limit(5)
            ->get()
            ->filter(fn ($sig) => $sig->instrument !== null)
            ->map(fn ($sig): array => [
                'id' => $sig->instrument->id,
                'symbol' => $sig->instrument->symbol,
                'name' => $sig->instrument->name,
                'exchange_code' => $sig->instrument->exchange->code,
                'currency' => $sig->instrument->currency,
                'stance' => $sig->stance,
                'entry_low' => $sig->entry_low !== null ? (string) $sig->entry_low : null,
                'entry_high' => $sig->entry_high !== null ? (string) $sig->entry_high : null,
                'target_1' => $sig->target_1 !== null ? (string) $sig->target_1 : null,
                'net_rr' => $sig->net_rr !== null ? (string) $sig->net_rr : null,
                'is_demo' => (bool) $sig->is_demo,
            ])
            ->values()
            ->all();

        $upcomingEvents = CorporateAction::query()
            ->with('instrument.exchange:id,code')
            ->whereDate('ex_date', '>=', now('UTC')->toDateString())
            ->orderBy('ex_date')
            ->limit(5)
            ->get()
            ->filter(fn ($a) => $a->instrument !== null)
            ->map(fn ($a): array => [
                'id' => $a->id,
                'symbol' => $a->instrument->symbol,
                'exchange_code' => $a->instrument->exchange->code,
                'action_type' => $a->action_type,
                'ex_date' => $a->ex_date->toDateString(),
                'declared_currency' => $a->declared_currency,
                'cash_amount' => $a->cash_amount !== null ? (string) $a->cash_amount : null,
                'is_demo' => (bool) $a->is_demo,
            ])
            ->values()
            ->all();

        $unreadAlertCount = AlertEvent::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $portfolioSummary = $valuation->summarize($user)['summary'];
        $curatedNews = $newsroom->latestForDashboard($user, 5);

        return Inertia::render('dashboard', [
            'indexCards' => $overview->indexCards(),
            'clocks' => $overview->clocks(),
            'breadth' => $overview->breadth($selectedExchange),
            'topMovers' => $overview->topMovers($selectedExchange, 5),
            'sectorPerformance' => $overview->sectorPerformance($selectedExchange),
            'watchlistItems' => $watchlistItems,
            'portfolioSummary' => $portfolioSummary,
            'safetyWarnings' => $safetyWarnings,
            'recentSignals' => $recentSignals,
            'curatedNews' => $curatedNews,
            'upcomingEvents' => $upcomingEvents,
            'unreadAlertCount' => $unreadAlertCount,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name', 'country_code']),
            'selectedExchange' => $selectedExchange,
            'dashboardLayout' => $this->normalizeLayout($user->settings?->dashboard_layout),
        ]);
    }

    public function updateLayout(Request $request): RedirectResponse
    {
        $allowed = config('nadi.dashboard_widgets', []);

        $validated = $request->validate([
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*.id' => ['required', 'string', Rule::in($allowed)],
            'widgets.*.visible' => ['required', 'boolean'],
        ]);

        $seen = [];
        $normalized = [];
        foreach ($validated['widgets'] as $widget) {
            if (isset($seen[$widget['id']])) {
                continue;
            }
            $seen[$widget['id']] = true;
            $normalized[] = [
                'id' => $widget['id'],
                'visible' => (bool) $widget['visible'],
            ];
        }

        foreach ($allowed as $id) {
            if (! isset($seen[$id])) {
                $normalized[] = ['id' => $id, 'visible' => true];
            }
        }

        $settings = $request->user()->settings()->firstOrCreate(
            ['user_id' => $request->user()->id],
            config('nadi.defaults'),
        );

        $settings->update(['dashboard_layout' => $normalized]);

        return back();
    }

    /**
     * @param  list<array{id?: string, visible?: bool}>|null  $saved
     * @return list<array{id: string, visible: bool}>
     */
    private function normalizeLayout(?array $saved): array
    {
        $allowed = config('nadi.dashboard_widgets', []);
        if (! is_array($saved) || empty($saved)) {
            return array_map(fn (string $id): array => ['id' => $id, 'visible' => true], $allowed);
        }

        $seen = [];
        $result = [];
        foreach ($saved as $item) {
            $id = $item['id'] ?? null;
            if (! is_string($id) || ! in_array($id, $allowed, true) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = ['id' => $id, 'visible' => (bool) ($item['visible'] ?? true)];
        }

        foreach ($allowed as $id) {
            if (! isset($seen[$id])) {
                $result[] = ['id' => $id, 'visible' => true];
            }
        }

        return $result;
    }
}
