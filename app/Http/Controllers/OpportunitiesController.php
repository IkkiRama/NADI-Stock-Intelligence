<?php

namespace App\Http\Controllers;

use App\Domain\TradingSignals\Services\SmartEntryExitService;
use App\Models\Exchange;
use App\Models\Instrument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpportunitiesController extends Controller
{
    public function index(Request $request): Response
    {
        $horizon = (string) $request->query('horizon', 'swing');
        if (! in_array($horizon, SmartEntryExitService::HORIZONS, true)) {
            $horizon = 'swing';
        }

        $exchangeFilter = strtoupper(trim((string) $request->query('exchange', 'ALL')));
        $stanceFilter = (string) $request->query('stance', 'ALL');
        if (! in_array($stanceFilter, ['ALL', 'actionable_setup', 'watchlist_setup'], true)) {
            $stanceFilter = 'ALL';
        }

        $minScore = is_numeric($request->query('min_score')) ? (string) $request->query('min_score') : '50';
        $maxRisk = is_numeric($request->query('max_risk')) ? (string) $request->query('max_risk') : '65';

        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_scores', 'instrument_scores.instrument_id', '=', 'instruments.id')
            ->join('stock_safety_assessments', 'stock_safety_assessments.instrument_id', '=', 'instruments.id')
            ->join('trading_signals', function ($join) use ($horizon): void {
                $join->on('trading_signals.instrument_id', '=', 'instruments.id')
                    ->where('trading_signals.horizon', '=', $horizon)
                    ->where('trading_signals.is_latest', '=', true);
            })
            ->where('instruments.is_active', true)
            ->where('stock_safety_assessments.safeguard_triggered', false)
            ->where('trading_signals.safeguard_suppressed', false)
            ->whereIn('trading_signals.stance', ['actionable_setup', 'watchlist_setup'])
            ->select([
                'instruments.id',
                'instruments.symbol',
                'instruments.name',
                'instruments.sector',
                'instruments.currency',
                'exchanges.code as exchange_code',
                'instrument_quotes_latest.close',
                'instrument_quotes_latest.change_pct',
                'instrument_quotes_latest.trade_date as as_of',
                'instrument_quotes_latest.is_demo',
                'instrument_scores.overall_score',
                'stock_safety_assessments.risk_score as safety_risk_score',
                'stock_safety_assessments.risk_category as safety_category',
                'trading_signals.horizon',
                'trading_signals.stance',
                'trading_signals.entry_low',
                'trading_signals.entry_high',
                'trading_signals.stop_loss',
                'trading_signals.target_1',
                'trading_signals.net_rr',
                'trading_signals.signal_date',
            ]);

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $query->where('exchanges.code', $exchangeFilter);
        }

        if ($stanceFilter !== 'ALL') {
            $query->where('trading_signals.stance', $stanceFilter);
        }

        if ($minScore !== '') {
            $query->where('instrument_scores.overall_score', '>=', $minScore);
        }

        if ($maxRisk !== '') {
            $query->where('stock_safety_assessments.risk_score', '<=', $maxRisk);
        }

        $query
            ->orderByRaw("CASE WHEN trading_signals.stance = 'actionable_setup' THEN 0 ELSE 1 END")
            ->orderByDesc('instrument_scores.overall_score')
            ->orderBy('instruments.symbol');

        $rows = $query->paginate(20)->withQueryString()->through(fn ($r): array => [
            'id' => (int) $r->id,
            'symbol' => $r->symbol,
            'name' => $r->name,
            'sector' => $r->sector,
            'currency' => $r->currency,
            'exchange_code' => $r->exchange_code,
            'close' => $r->close !== null ? (string) $r->close : null,
            'change_pct' => $r->change_pct !== null ? (string) $r->change_pct : null,
            'overall_score' => $r->overall_score !== null ? (string) $r->overall_score : null,
            'safety_risk_score' => $r->safety_risk_score !== null ? (string) $r->safety_risk_score : null,
            'safety_category' => $r->safety_category,
            'horizon' => $r->horizon,
            'stance' => $r->stance,
            'entry_low' => $r->entry_low !== null ? (string) $r->entry_low : null,
            'entry_high' => $r->entry_high !== null ? (string) $r->entry_high : null,
            'stop_loss' => $r->stop_loss !== null ? (string) $r->stop_loss : null,
            'target_1' => $r->target_1 !== null ? (string) $r->target_1 : null,
            'net_rr' => $r->net_rr !== null ? (string) $r->net_rr : null,
            'as_of' => $r->as_of ? substr((string) $r->as_of, 0, 10) : null,
            'is_demo' => (bool) $r->is_demo,
        ]);

        return Inertia::render('intelligence/opportunities', [
            'opportunities' => $rows,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'filters' => [
                'horizon' => $horizon,
                'exchange' => $exchangeFilter,
                'stance' => $stanceFilter,
                'min_score' => $minScore,
                'max_risk' => $maxRisk,
            ],
        ]);
    }
}
