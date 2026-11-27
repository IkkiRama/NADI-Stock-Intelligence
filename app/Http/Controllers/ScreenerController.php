<?php

namespace App\Http\Controllers;

use App\Domain\MarketData\Services\CsvSanitizer;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\SavedScreener;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScreenerController extends Controller
{
    private const SORT_MAP = [
        'overall_score' => 'instrument_scores.overall_score',
        'symbol' => 'instruments.symbol',
        'close' => 'instrument_quotes_latest.close',
        'change_pct' => 'instrument_quotes_latest.change_pct',
        'return_1m' => 'instrument_technicals_latest.return_1m',
        'market_cap_idr' => 'instrument_fundamentals_latest.market_cap_idr',
        'per' => 'instrument_fundamentals_latest.per',
        'pbv' => 'instrument_fundamentals_latest.pbv',
        'roe' => 'instrument_fundamentals_latest.roe',
        'dividend_yield' => 'instrument_fundamentals_latest.dividend_yield',
        'rsi_14' => 'instrument_technicals_latest.rsi_14',
        'volume' => 'instrument_quotes_latest.volume',
        'safety_risk' => 'stock_safety_assessments.risk_score',
    ];

    public function index(Request $request): Response
    {
        $filters = $this->extractFilters($request);
        $sort = array_key_exists($filters['sort'], self::SORT_MAP) ? $filters['sort'] : 'overall_score';
        $dir = $filters['dir'] === 'asc' ? 'asc' : 'desc';

        $query = $this->buildQuery($filters)
            ->orderBy(self::SORT_MAP[$sort], $dir)
            ->orderBy('instruments.symbol');

        $rows = $query->paginate(25)->withQueryString()->through(fn ($row): array => $this->serializeRow($row));

        $watchlist = $request->user()->watchlists()->where('is_default', true)->first();
        $watchlistedIds = $watchlist
            ? $watchlist->items()->pluck('instrument_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return Inertia::render('stocks/screener', [
            'results' => $rows,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'sectors' => Instrument::query()->where('is_active', true)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector')->values()->all(),
            'countries' => Instrument::query()->where('is_active', true)->distinct()->orderBy('country_code')->pluck('country_code')->values()->all(),
            'watchlistedIds' => $watchlistedIds,
            'savedScreeners' => $request->user()->savedScreeners()->get(['id', 'name', 'filters', 'sort_by', 'sort_dir', 'updated_at']),
            'filters' => array_merge($filters, ['sort' => $sort, 'dir' => $dir]),
        ]);
    }

    public function storePreset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'sort_by' => ['nullable', 'string', 'max:40'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $sortBy = array_key_exists($validated['sort_by'] ?? '', self::SORT_MAP)
            ? $validated['sort_by']
            : 'overall_score';

        $request->user()->savedScreeners()->create([
            'name' => $validated['name'],
            'filters' => $validated['filters'] ?? [],
            'sort_by' => $sortBy,
            'sort_dir' => $validated['sort_dir'] ?? 'desc',
        ]);

        return back()->with('status', 'screener-preset-saved');
    }

    public function destroyPreset(Request $request, SavedScreener $savedScreener): RedirectResponse
    {
        abort_if($savedScreener->user_id !== $request->user()->id, 403);

        $savedScreener->delete();

        return back()->with('status', 'screener-preset-deleted');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->extractFilters($request);
        $sort = array_key_exists($filters['sort'], self::SORT_MAP) ? $filters['sort'] : 'overall_score';
        $dir = $filters['dir'] === 'asc' ? 'asc' : 'desc';

        $rows = $this->buildQuery($filters)
            ->orderBy(self::SORT_MAP[$sort], $dir)
            ->orderBy('instruments.symbol')
            ->limit(1000)
            ->get();

        $filename = 'nadi-screener-'.now('UTC')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'Exchange', 'Symbol', 'Name', 'Sector', 'Currency', 'Price', 'ChangePct',
                'Return1M', 'MarketCapIDR', 'PER', 'PBV', 'ROE', 'DividendYield',
                'RSI14', 'Score', 'SafetyRiskScore', 'SafetyCategory', 'SafeguardFlagged', 'AsOf', 'IsDemo', 'ExportedAtUTC',
            ]);

            $exportedAt = now('UTC')->toIso8601String();
            foreach ($rows as $r) {
                fputcsv($out, [
                    CsvSanitizer::escapeCell($r->exchange_code),
                    CsvSanitizer::escapeCell($r->symbol),
                    CsvSanitizer::escapeCell($r->name),
                    CsvSanitizer::escapeCell($r->sector ?? ''),
                    CsvSanitizer::escapeCell($r->currency),
                    CsvSanitizer::escapeCell($r->close),
                    CsvSanitizer::escapeCell($r->change_pct),
                    CsvSanitizer::escapeCell($r->return_1m),
                    CsvSanitizer::escapeCell($r->market_cap_idr),
                    CsvSanitizer::escapeCell($r->per),
                    CsvSanitizer::escapeCell($r->pbv),
                    CsvSanitizer::escapeCell($r->roe),
                    CsvSanitizer::escapeCell($r->dividend_yield),
                    CsvSanitizer::escapeCell($r->rsi_14),
                    CsvSanitizer::escapeCell($r->overall_score),
                    CsvSanitizer::escapeCell($r->safety_risk_score),
                    CsvSanitizer::escapeCell($r->safety_category ?? ''),
                    $r->safeguard_triggered ? 'true' : 'false',
                    CsvSanitizer::escapeCell($r->as_of ? substr((string) $r->as_of, 0, 10) : ''),
                    $r->is_demo ? 'true' : 'false',
                    $exportedAt,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'exchange' => strtoupper(trim((string) $request->query('exchange', ''))),
            'sector' => trim((string) $request->query('sector', '')),
            'country' => strtoupper(trim((string) $request->query('country', ''))),
            'price_min' => $this->numParam($request->query('price_min')),
            'price_max' => $this->numParam($request->query('price_max')),
            'mcap_idr_min' => $this->numParam($request->query('mcap_idr_min')),
            'mcap_idr_max' => $this->numParam($request->query('mcap_idr_max')),
            'avg_vol_min' => $this->numParam($request->query('avg_vol_min')),
            'per_max' => $this->numParam($request->query('per_max')),
            'pbv_max' => $this->numParam($request->query('pbv_max')),
            'roe_min' => $this->numParam($request->query('roe_min')),
            'div_yield_min' => $this->numParam($request->query('div_yield_min')),
            'rev_growth_min' => $this->numParam($request->query('rev_growth_min')),
            'profitable_only' => $request->boolean('profitable_only'),
            'rsi_min' => $this->numParam($request->query('rsi_min')),
            'rsi_max' => $this->numParam($request->query('rsi_max')),
            'above_sma50' => $request->boolean('above_sma50'),
            'above_sma200' => $request->boolean('above_sma200'),
            'rel_strength_min' => $this->numParam($request->query('rel_strength_min')),
            'score_min' => $this->numParam($request->query('score_min')),
            'max_safety_risk' => $this->numParam($request->query('max_safety_risk')),
            'exclude_flagged' => $request->boolean('exclude_flagged'),
            'sort' => (string) $request->query('sort', 'overall_score'),
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function numParam(mixed $val): ?string
    {
        if ($val === null || trim((string) $val) === '' || ! is_numeric((string) $val)) {
            return null;
        }

        return (string) $val;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(array $filters): Builder
    {
        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_fundamentals_latest', 'instrument_fundamentals_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_technicals_latest', 'instrument_technicals_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_scores', 'instrument_scores.instrument_id', '=', 'instruments.id')
            ->leftJoin('stock_safety_assessments', 'stock_safety_assessments.instrument_id', '=', 'instruments.id')
            ->where('instruments.is_active', true)
            ->select([
                'instruments.id',
                'instruments.symbol',
                'instruments.name',
                'instruments.sector',
                'instruments.currency',
                'instruments.country_code',
                'exchanges.code as exchange_code',
                'instrument_quotes_latest.close',
                'instrument_quotes_latest.change_pct',
                'instrument_quotes_latest.volume',
                'instrument_quotes_latest.trade_date as as_of',
                'instrument_quotes_latest.is_demo',
                'instrument_fundamentals_latest.market_cap',
                'instrument_fundamentals_latest.market_cap_idr',
                'instrument_fundamentals_latest.eps',
                'instrument_fundamentals_latest.per',
                'instrument_fundamentals_latest.pbv',
                'instrument_fundamentals_latest.roe',
                'instrument_fundamentals_latest.net_margin',
                'instrument_fundamentals_latest.revenue_growth_yoy',
                'instrument_fundamentals_latest.dividend_yield',
                'instrument_technicals_latest.rsi_14',
                'instrument_technicals_latest.sma_50',
                'instrument_technicals_latest.sma_200',
                'instrument_technicals_latest.return_1m',
                'instrument_technicals_latest.relative_strength_1m',
                'instrument_scores.overall_score',
                'instrument_scores.data_coverage',
                'stock_safety_assessments.risk_score as safety_risk_score',
                'stock_safety_assessments.risk_category as safety_category',
                'stock_safety_assessments.safeguard_triggered',
            ]);

        if ($filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('instruments.symbol', 'like', $like)
                    ->orWhere('instruments.name', 'like', $like);
            });
        }

        if ($filters['exchange'] !== '' && $filters['exchange'] !== 'ALL') {
            $query->where('exchanges.code', $filters['exchange']);
        }
        if ($filters['sector'] !== '' && $filters['sector'] !== 'ALL') {
            $query->where('instruments.sector', $filters['sector']);
        }
        if ($filters['country'] !== '' && $filters['country'] !== 'ALL') {
            $query->where('instruments.country_code', $filters['country']);
        }

        if ($filters['price_min'] !== null) {
            $query->where('instrument_quotes_latest.close', '>=', $filters['price_min']);
        }
        if ($filters['price_max'] !== null) {
            $query->where('instrument_quotes_latest.close', '<=', $filters['price_max']);
        }
        if ($filters['mcap_idr_min'] !== null) {
            $query->where('instrument_fundamentals_latest.market_cap_idr', '>=', $filters['mcap_idr_min']);
        }
        if ($filters['mcap_idr_max'] !== null) {
            $query->where('instrument_fundamentals_latest.market_cap_idr', '<=', $filters['mcap_idr_max']);
        }
        if ($filters['avg_vol_min'] !== null) {
            $query->where('instrument_quotes_latest.volume', '>=', $filters['avg_vol_min']);
        }
        if ($filters['per_max'] !== null) {
            $query->whereNotNull('instrument_fundamentals_latest.per')
                ->where('instrument_fundamentals_latest.per', '>', 0)
                ->where('instrument_fundamentals_latest.per', '<=', $filters['per_max']);
        }
        if ($filters['pbv_max'] !== null) {
            $query->whereNotNull('instrument_fundamentals_latest.pbv')
                ->where('instrument_fundamentals_latest.pbv', '<=', $filters['pbv_max']);
        }
        if ($filters['roe_min'] !== null) {
            $query->where('instrument_fundamentals_latest.roe', '>=', $filters['roe_min']);
        }
        if ($filters['div_yield_min'] !== null) {
            $query->where('instrument_fundamentals_latest.dividend_yield', '>=', $filters['div_yield_min']);
        }
        if ($filters['rev_growth_min'] !== null) {
            $query->where('instrument_fundamentals_latest.revenue_growth_yoy', '>=', $filters['rev_growth_min']);
        }
        if ($filters['profitable_only']) {
            $query->whereNotNull('instrument_fundamentals_latest.eps')
                ->where('instrument_fundamentals_latest.eps', '>', 0);
        }
        if ($filters['rsi_min'] !== null) {
            $query->where('instrument_technicals_latest.rsi_14', '>=', $filters['rsi_min']);
        }
        if ($filters['rsi_max'] !== null) {
            $query->where('instrument_technicals_latest.rsi_14', '<=', $filters['rsi_max']);
        }
        if ($filters['above_sma50']) {
            $query->whereNotNull('instrument_technicals_latest.sma_50')
                ->whereColumn('instrument_quotes_latest.close', '>=', 'instrument_technicals_latest.sma_50');
        }
        if ($filters['above_sma200']) {
            $query->whereNotNull('instrument_technicals_latest.sma_200')
                ->whereColumn('instrument_quotes_latest.close', '>=', 'instrument_technicals_latest.sma_200');
        }
        if ($filters['rel_strength_min'] !== null) {
            $query->where('instrument_technicals_latest.relative_strength_1m', '>=', $filters['rel_strength_min']);
        }
        if ($filters['score_min'] !== null) {
            $query->where('instrument_scores.overall_score', '>=', $filters['score_min']);
        }
        if ($filters['max_safety_risk'] !== null) {
            $query->whereNotNull('stock_safety_assessments.risk_score')
                ->where('stock_safety_assessments.risk_score', '<=', $filters['max_safety_risk']);
        }
        if ($filters['exclude_flagged']) {
            $query->where(function ($sub): void {
                $sub->whereNull('stock_safety_assessments.safeguard_triggered')
                    ->orWhere('stock_safety_assessments.safeguard_triggered', false);
            });
        }

        return $query;
    }

    private function serializeRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'symbol' => $row->symbol,
            'name' => $row->name,
            'sector' => $row->sector,
            'currency' => $row->currency,
            'country_code' => $row->country_code,
            'exchange_code' => $row->exchange_code,
            'close' => $row->close !== null ? (string) $row->close : null,
            'change_pct' => $row->change_pct !== null ? (string) $row->change_pct : null,
            'return_1m' => $row->return_1m !== null ? (string) $row->return_1m : null,
            'volume' => $row->volume !== null ? (int) $row->volume : null,
            'market_cap' => $row->market_cap !== null ? (string) $row->market_cap : null,
            'market_cap_idr' => $row->market_cap_idr !== null ? (string) $row->market_cap_idr : null,
            'eps' => $row->eps !== null ? (string) $row->eps : null,
            'per' => $row->per !== null ? (string) $row->per : null,
            'pbv' => $row->pbv !== null ? (string) $row->pbv : null,
            'roe' => $row->roe !== null ? (string) $row->roe : null,
            'dividend_yield' => $row->dividend_yield !== null ? (string) $row->dividend_yield : null,
            'rsi_14' => $row->rsi_14 !== null ? (string) $row->rsi_14 : null,
            'overall_score' => $row->overall_score !== null ? (string) $row->overall_score : null,
            'data_coverage' => $row->data_coverage !== null ? (string) $row->data_coverage : null,
            'safety_risk_score' => $row->safety_risk_score !== null ? (string) $row->safety_risk_score : null,
            'safety_category' => $row->safety_category ?? 'insufficient_data',
            'safeguard_triggered' => (bool) ($row->safeguard_triggered ?? false),
            'as_of' => $row->as_of ? substr((string) $row->as_of, 0, 10) : null,
            'is_demo' => (bool) $row->is_demo,
        ];
    }
}
