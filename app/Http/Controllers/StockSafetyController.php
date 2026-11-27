<?php

namespace App\Http\Controllers;

use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\StockSafetyAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockSafetyController extends Controller
{
    public function index(Request $request): Response
    {
        $exchangeFilter = strtoupper(trim((string) $request->query('exchange', 'ALL')));
        $category = strtolower(trim((string) $request->query('category', 'ALL')));
        if (! in_array($category, ['ALL', 'high', 'elevated', 'moderate', 'low', 'insufficient_data'], true)) {
            $category = 'ALL';
        }
        $safeguardOnly = $request->boolean('safeguard_only');
        $search = trim((string) $request->query('q', ''));

        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('stock_safety_assessments', 'stock_safety_assessments.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_regulatory_statuses', 'instrument_regulatory_statuses.instrument_id', '=', 'instruments.id')
            ->where('instruments.is_active', true)
            ->select([
                'instruments.id',
                'instruments.symbol',
                'instruments.name',
                'instruments.sector',
                'instruments.currency',
                'exchanges.code as exchange_code',
                'instrument_quotes_latest.close',
                'instrument_quotes_latest.change_pct',
                'stock_safety_assessments.as_of_date',
                'stock_safety_assessments.risk_score',
                'stock_safety_assessments.risk_category',
                'stock_safety_assessments.safeguard_triggered',
                'stock_safety_assessments.triggered_rules',
                'stock_safety_assessments.data_confidence',
                'stock_safety_assessments.missing_factors',
                'stock_safety_assessments.is_demo',
                'instrument_regulatory_statuses.is_suspended',
                'instrument_regulatory_statuses.has_special_monitoring',
                'instrument_regulatory_statuses.has_exchange_warning',
            ]);

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $query->where('exchanges.code', $exchangeFilter);
        }

        if ($category !== 'ALL') {
            $query->where('stock_safety_assessments.risk_category', $category);
        }

        if ($safeguardOnly) {
            $query->where('stock_safety_assessments.safeguard_triggered', true);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('instruments.symbol', 'like', $like)
                    ->orWhere('instruments.name', 'like', $like);
            });
        }

        $query->orderByDesc('stock_safety_assessments.safeguard_triggered')
            ->orderByDesc('stock_safety_assessments.risk_score')
            ->orderBy('instruments.symbol');

        $assessments = $query->paginate(25)->withQueryString()->through(function ($row): array {
            $rules = is_string($row->triggered_rules)
                ? (json_decode($row->triggered_rules, true) ?: [])
                : ($row->triggered_rules ?? []);
            $missing = is_string($row->missing_factors)
                ? (json_decode($row->missing_factors, true) ?: [])
                : ($row->missing_factors ?? []);

            return [
                'id' => (int) $row->id,
                'symbol' => $row->symbol,
                'name' => $row->name,
                'sector' => $row->sector,
                'currency' => $row->currency,
                'exchange_code' => $row->exchange_code,
                'close' => $row->close !== null ? (string) $row->close : null,
                'change_pct' => $row->change_pct !== null ? (string) $row->change_pct : null,
                'as_of_date' => $row->as_of_date ? substr((string) $row->as_of_date, 0, 10) : null,
                'risk_score' => $row->risk_score !== null ? (string) $row->risk_score : null,
                'risk_category' => $row->risk_category ?? 'insufficient_data',
                'safeguard_triggered' => (bool) $row->safeguard_triggered,
                'triggered_rules' => $rules,
                'data_confidence' => $row->data_confidence !== null ? (string) $row->data_confidence : null,
                'missing_factors' => $missing,
                'is_suspended' => (bool) $row->is_suspended,
                'has_special_monitoring' => (bool) $row->has_special_monitoring,
                'has_exchange_warning' => (bool) $row->has_exchange_warning,
                'is_demo' => (bool) $row->is_demo,
            ];
        });

        $summary = [
            'safeguard' => StockSafetyAssessment::query()->where('safeguard_triggered', true)->count(),
            'high' => StockSafetyAssessment::query()->where('risk_category', 'high')->count(),
            'elevated' => StockSafetyAssessment::query()->where('risk_category', 'elevated')->count(),
            'moderate' => StockSafetyAssessment::query()->where('risk_category', 'moderate')->count(),
            'low' => StockSafetyAssessment::query()->where('risk_category', 'low')->count(),
        ];

        return Inertia::render('intelligence/stock-safety', [
            'assessments' => $assessments,
            'summary' => $summary,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'filters' => [
                'q' => $search,
                'exchange' => $exchangeFilter,
                'category' => $category,
                'safeguard_only' => $safeguardOnly,
            ],
        ]);
    }
}
