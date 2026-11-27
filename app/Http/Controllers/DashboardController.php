<?php

namespace App\Http\Controllers;

use App\Domain\MarketData\Services\MarketOverviewService;
use App\Models\Exchange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, MarketOverviewService $overview): Response
    {
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

        return Inertia::render('dashboard', [
            'indexCards' => $overview->indexCards(),
            'clocks' => $overview->clocks(),
            'breadth' => $overview->breadth($selectedExchange),
            'topMovers' => $overview->topMovers($selectedExchange, 5),
            'sectorPerformance' => $overview->sectorPerformance($selectedExchange),
            'watchlistItems' => $watchlistItems,
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
