<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Services\AlertEvaluator;
use App\Models\AlertEvent;
use App\Models\CorporateAction;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\MarketHoliday;
use App\Models\UserAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertsCalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $exchangeFilter = strtoupper(trim((string) $request->query('exchange', 'ALL')));

        $alerts = $user->alerts()
            ->with(['instrument.exchange:id,code', 'instrument.latestQuote:instrument_id,close'])
            ->get()
            ->filter(fn ($a) => $a->instrument !== null)
            ->map(fn ($a): array => [
                'id' => $a->id,
                'instrument_id' => $a->instrument_id,
                'symbol' => $a->instrument->symbol,
                'name' => $a->instrument->name,
                'exchange_code' => $a->instrument->exchange->code,
                'currency' => $a->instrument->currency,
                'latest_close' => $a->instrument->latestQuote ? (string) $a->instrument->latestQuote->close : null,
                'alert_type' => $a->alert_type,
                'threshold_value' => $a->threshold_value !== null ? (string) $a->threshold_value : null,
                'horizon' => $a->horizon,
                'notes' => $a->notes,
                'is_active' => (bool) $a->is_active,
                'last_triggered_at' => $a->last_triggered_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $events = $user->alertEvents()
            ->with(['instrument.exchange:id,code'])
            ->limit(30)
            ->get()
            ->map(fn ($e): array => [
                'id' => $e->id,
                'symbol' => $e->instrument?->symbol ?? '',
                'exchange_code' => $e->instrument?->exchange?->code ?? '',
                'triggered_date' => $e->triggered_date->toDateString(),
                'message' => $e->message,
                'read_at' => $e->read_at?->toIso8601String(),
            ])
            ->all();

        $unreadCount = AlertEvent::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $actionsQuery = CorporateAction::query()
            ->with(['instrument.exchange:id,code'])
            ->whereDate('ex_date', '>=', now('UTC')->subDays(30)->toDateString())
            ->orderBy('ex_date');

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $actionsQuery->whereHas('instrument.exchange', fn ($q) => $q->where('code', $exchangeFilter));
        }

        $corporateActions = $actionsQuery->limit(40)->get()->map(fn ($a): array => [
            'id' => $a->id,
            'symbol' => $a->instrument?->symbol ?? '',
            'exchange_code' => $a->instrument?->exchange?->code ?? '',
            'action_type' => $a->action_type,
            'ex_date' => $a->ex_date->toDateString(),
            'payment_date' => $a->payment_date?->toDateString(),
            'declared_currency' => $a->declared_currency,
            'cash_amount' => $a->cash_amount !== null ? (string) $a->cash_amount : null,
            'ratio_numerator' => $a->ratio_numerator !== null ? (string) $a->ratio_numerator : null,
            'ratio_denominator' => $a->ratio_denominator !== null ? (string) $a->ratio_denominator : null,
            'notes' => $a->notes,
            'is_demo' => (bool) $a->is_demo,
        ])->all();

        $holidayQuery = MarketHoliday::query()
            ->with('exchange:id,code,name')
            ->whereDate('holiday_date', '>=', now('UTC')->subDays(7)->toDateString())
            ->orderBy('holiday_date');

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $holidayQuery->whereHas('exchange', fn ($q) => $q->where('code', $exchangeFilter));
        }

        $holidays = $holidayQuery->limit(30)->get()->map(fn ($h): array => [
            'id' => $h->id,
            'exchange_code' => $h->exchange?->code ?? '',
            'holiday_date' => $h->holiday_date->toDateString(),
            'name' => $h->name,
        ])->all();

        $instruments = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->where('instruments.is_active', true)
            ->orderBy('exchanges.id')
            ->orderBy('instruments.symbol')
            ->get(['instruments.id', 'instruments.symbol', 'instruments.name', 'instruments.currency', 'exchanges.code as exchange_code'])
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'symbol' => $r->symbol,
                'name' => $r->name,
                'currency' => $r->currency,
                'exchange_code' => $r->exchange_code,
            ])
            ->all();

        return Inertia::render('tools/alerts-calendar', [
            'alerts' => $alerts,
            'events' => $events,
            'unreadCount' => $unreadCount,
            'corporateActions' => $corporateActions,
            'holidays' => $holidays,
            'instruments' => $instruments,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'selectedExchange' => $exchangeFilter,
        ]);
    }

    public function store(Request $request, AlertEvaluator $evaluator): RedirectResponse
    {
        $validated = $request->validate([
            'instrument_id' => ['required', 'integer', 'exists:instruments,id'],
            'alert_type' => ['required', 'string', Rule::in(['price_above', 'price_below', 'safety_risk_above', 'signal_actionable'])],
            'threshold_value' => [
                Rule::requiredIf(fn () => $request->input('alert_type') !== 'signal_actionable'),
                'nullable',
                'numeric',
                'min:0',
            ],
            'horizon' => ['nullable', 'string', Rule::in(['short_term', 'swing', 'long_term'])],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $request->user()->alerts()->create([
            'instrument_id' => (int) $validated['instrument_id'],
            'alert_type' => $validated['alert_type'],
            'threshold_value' => $validated['alert_type'] === 'signal_actionable' ? null : $validated['threshold_value'],
            'horizon' => $validated['alert_type'] === 'signal_actionable' ? ($validated['horizon'] ?: 'swing') : null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => true,
        ]);

        $evaluator->evaluateAll();

        return back()->with('status', 'alert-created');
    }

    public function toggle(Request $request, UserAlert $userAlert): RedirectResponse
    {
        abort_if($userAlert->user_id !== $request->user()->id, 403);

        $userAlert->update(['is_active' => ! $userAlert->is_active]);

        return back()->with('status', 'alert-updated');
    }

    public function destroy(Request $request, UserAlert $userAlert): RedirectResponse
    {
        abort_if($userAlert->user_id !== $request->user()->id, 403);

        $userAlert->delete();

        return back()->with('status', 'alert-deleted');
    }

    public function markEventRead(Request $request, AlertEvent $alertEvent): RedirectResponse
    {
        abort_if($alertEvent->user_id !== $request->user()->id, 403);

        $alertEvent->forceFill(['read_at' => now('UTC')])->save();

        return back();
    }
}
