import { StrategyEquityChart, type StrategyCurvePoint } from '@/components/nadi/strategy-equity-chart';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatDateTime, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BookmarkPlus, Download, FlaskConical, Play, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface TradeRow {
    trade_no: number;
    signal_date: string;
    entry_date: string;
    exit_date: string;
    holding_days: number;
    shares: number;
    entry_price: string;
    exit_price: string;
    gross_pnl: string;
    total_fees: string;
    net_pnl: string;
    return_pct: string;
    exit_reason: string;
}

interface BacktestPayload {
    instrument: {
        id: number;
        exchange_code: string;
        symbol: string;
        name: string;
        currency: string;
        lot_size: number;
    };
    strategy: string;
    range: string;
    start_date: string | null;
    end_date: string | null;
    trading_days: number;
    calendar_days: number;
    is_demo: boolean;
    parameters: Record<string, string | number | null>;
    summary: {
        initial_capital: string;
        final_equity: string;
        net_profit: string;
        total_return_pct: string;
        cagr_pct: string | null;
        buy_hold_final_equity: string;
        buy_hold_return_pct: string;
        excess_return_pct: string;
        max_drawdown_pct: string;
        annualized_volatility_pct: string | null;
        sharpe_ratio: string | null;
        sortino_ratio: string | null;
        calmar_ratio: string | null;
        closed_trades_count: number;
        win_count: number;
        loss_count: number;
        win_rate_pct: string | null;
        profit_factor: string | null;
        avg_win_pct: string | null;
        avg_loss_pct: string | null;
        avg_holding_days: number | null;
        exposure_time_pct: string;
        total_fees_paid: string;
        in_sample_return_pct: string | null;
        out_of_sample_return_pct: string | null;
        in_sample_split_date: string | null;
    };
    open_position: {
        signal_date: string;
        entry_date: string;
        shares: number;
        entry_price: string;
        mark_price: string;
        market_value: string;
        unrealized_pnl: string;
        unrealized_pct: string | null;
        stop_price: string | null;
        tp_price: string | null;
    } | null;
    trades: TradeRow[];
    equity_curve: StrategyCurvePoint[];
}

interface SavedRunRow {
    id: number;
    name: string | null;
    symbol: string;
    exchange_code: string;
    strategy_code: string;
    range_code: string;
    currency: string;
    initial_capital: string;
    parameters: Record<string, string | number | null>;
    trades_count: number;
    total_return_pct: string | null;
    buy_hold_return_pct: string | null;
    max_drawdown_pct: string | null;
    sharpe_ratio: string | null;
    win_rate_pct: string | null;
    is_demo: boolean;
    executed_at: string | null;
}

interface StrategyLabProps {
    backtest: BacktestPayload | null;
    strategies: string[];
    ranges: string[];
    instruments: Array<{
        id: number;
        exchange_code: string;
        symbol: string;
        name: string;
        currency: string;
        lot_size: number;
    }>;
    filters: Record<string, string | number | null | undefined>;
    savedRuns: SavedRunRow[];
}

export default function StrategyLabIndex({
    backtest,
    strategies,
    ranges,
    instruments,
    filters,
    savedRuns,
}: StrategyLabProps) {
    const { t, locale } = useT();
    const [chartMode, setChartMode] = useState<'equity' | 'drawdown'>('equity');

    const [symbol, setSymbol] = useState(String(filters.symbol ?? 'BBCA'));
    const [strategy, setStrategy] = useState(String(filters.strategy ?? 'sma_crossover'));
    const [range, setRange] = useState(String(filters.range ?? '1Y'));
    const [initialCapital, setInitialCapital] = useState(String(filters.initial_capital ?? ''));
    const [allocationPct, setAllocationPct] = useState(String(filters.allocation_pct ?? '0.95'));
    const [buyFeePct, setBuyFeePct] = useState(String(filters.buy_fee_pct ?? ''));
    const [sellFeePct, setSellFeePct] = useState(String(filters.sell_fee_pct ?? ''));
    const [slippagePct, setSlippagePct] = useState(String(filters.slippage_pct ?? ''));
    const [stopLossPct, setStopLossPct] = useState(filters.stop_loss_pct ? String(filters.stop_loss_pct) : '');
    const [takeProfitPct, setTakeProfitPct] = useState(filters.take_profit_pct ? String(filters.take_profit_pct) : '');

    const [fastPeriod, setFastPeriod] = useState(String(filters.fast_period ?? 20));
    const [slowPeriod, setSlowPeriod] = useState(String(filters.slow_period ?? 50));
    const [rsiPeriod, setRsiPeriod] = useState(String(filters.rsi_period ?? 14));
    const [oversold, setOversold] = useState(String(filters.oversold ?? 30));
    const [overbought, setOverbought] = useState(String(filters.overbought ?? 70));
    const [fastEma, setFastEma] = useState(String(filters.fast_ema ?? 12));
    const [slowEma, setSlowEma] = useState(String(filters.slow_ema ?? 26));
    const [signalEma, setSignalEma] = useState(String(filters.signal_ema ?? 9));
    const [entryLookback, setEntryLookback] = useState(String(filters.entry_lookback ?? 20));
    const [exitLookback, setExitLookback] = useState(String(filters.exit_lookback ?? 10));
    const [snapshotName, setSnapshotName] = useState('');

    useEffect(() => {
        setSymbol(String(filters.symbol ?? 'BBCA'));
        setStrategy(String(filters.strategy ?? 'sma_crossover'));
        setRange(String(filters.range ?? '1Y'));
        setInitialCapital(String(filters.initial_capital ?? ''));
        setAllocationPct(String(filters.allocation_pct ?? '0.95'));
        setBuyFeePct(String(filters.buy_fee_pct ?? ''));
        setSellFeePct(String(filters.sell_fee_pct ?? ''));
        setSlippagePct(String(filters.slippage_pct ?? ''));
        setStopLossPct(filters.stop_loss_pct ? String(filters.stop_loss_pct) : '');
        setTakeProfitPct(filters.take_profit_pct ? String(filters.take_profit_pct) : '');
        if (filters.fast_period) setFastPeriod(String(filters.fast_period));
        if (filters.slow_period) setSlowPeriod(String(filters.slow_period));
        if (filters.rsi_period) setRsiPeriod(String(filters.rsi_period));
        if (filters.oversold) setOversold(String(filters.oversold));
        if (filters.overbought) setOverbought(String(filters.overbought));
        if (filters.fast_ema) setFastEma(String(filters.fast_ema));
        if (filters.slow_ema) setSlowEma(String(filters.slow_ema));
        if (filters.signal_ema) setSignalEma(String(filters.signal_ema));
        if (filters.entry_lookback) setEntryLookback(String(filters.entry_lookback));
        if (filters.exit_lookback) setExitLookback(String(filters.exit_lookback));
    }, [filters]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.strategy_lab'), href: '/strategy-lab' }];

    const buildQueryPayload = (overrideSymbol?: string) => {
        const base: Record<string, string> = {
            symbol: overrideSymbol ?? symbol,
            strategy,
            range,
        };
        if (initialCapital.trim() !== '') base.initial_capital = initialCapital.trim();
        if (allocationPct.trim() !== '') base.allocation_pct = allocationPct.trim();
        if (buyFeePct.trim() !== '') base.buy_fee_pct = buyFeePct.trim();
        if (sellFeePct.trim() !== '') base.sell_fee_pct = sellFeePct.trim();
        if (slippagePct.trim() !== '') base.slippage_pct = slippagePct.trim();
        if (stopLossPct.trim() !== '') base.stop_loss_pct = stopLossPct.trim();
        if (takeProfitPct.trim() !== '') base.take_profit_pct = takeProfitPct.trim();

        if (strategy === 'sma_crossover') {
            base.fast_period = fastPeriod;
            base.slow_period = slowPeriod;
        } else if (strategy === 'rsi_mean_reversion') {
            base.rsi_period = rsiPeriod;
            base.oversold = oversold;
            base.overbought = overbought;
        } else if (strategy === 'macd_momentum') {
            base.fast_ema = fastEma;
            base.slow_ema = slowEma;
            base.signal_ema = signalEma;
        } else if (strategy === 'donchian_breakout') {
            base.entry_lookback = entryLookback;
            base.exit_lookback = exitLookback;
        }
        return base;
    };

    const runSimulation = (e?: React.FormEvent) => {
        e?.preventDefault();
        router.get('/strategy-lab', buildQueryPayload(), { preserveScroll: true });
    };

    const saveSnapshot = () => {
        router.post(
            '/strategy-lab/runs',
            { ...buildQueryPayload(), name: snapshotName.trim() || null },
            { preserveScroll: true, onSuccess: () => setSnapshotName('') },
        );
    };

    const csvQuery = new URLSearchParams(buildQueryPayload()).toString();
    const ccy = backtest?.instrument.currency ?? 'IDR';
    const s = backtest?.summary;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('strategy_lab.title')} />

            <div className="space-y-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{t('strategy_lab.title')}</h1>
                            {backtest?.is_demo && <DemoBadge />}
                        </div>
                        <p className="text-muted-foreground mt-0.5 text-xs">{t('strategy_lab.subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {backtest?.end_date && (
                            <DataFreshness asOf={backtest.end_date} source="EOD OHLCV Engine" isDemo={backtest.is_demo} />
                        )}
                        {backtest && (
                            <Button asChild size="sm" variant="outline">
                                <a href={`/strategy-lab/export-csv?${csvQuery}`}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    {t('strategy_lab.action.export_csv')}
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Look-Ahead Safeguard Strip */}
                <div className="bg-muted/40 border-border flex flex-wrap items-center justify-between gap-2 rounded-md border px-3.5 py-2.5 text-xs">
                    <div className="flex items-center gap-2">
                        <ShieldCheck className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span className="font-semibold">{t('strategy_lab.safeguard_badge')}</span>
                        <span className="text-muted-foreground hidden sm:inline">— {t('strategy_lab.safeguard_desc')}</span>
                    </div>
                    {s && (
                        <div className="flex items-center gap-3 font-mono text-[11px]">
                            <span>
                                {t('strategy_lab.wf.in_sample')}:{' '}
                                <strong>{formatPct(s.in_sample_return_pct, locale)}</strong>
                            </span>
                            <span>
                                {t('strategy_lab.wf.out_of_sample')}:{' '}
                                <strong>{formatPct(s.out_of_sample_return_pct, locale)}</strong>
                            </span>
                        </div>
                    )}
                </div>

                {/* Parameter Studio */}
                <form onSubmit={runSimulation} className="bg-card border-border space-y-4 rounded-md border p-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-1">
                            <Label className="text-xs">{t('strategy_lab.field.symbol')}</Label>
                            <Select
                                value={symbol}
                                onValueChange={(v) => {
                                    setSymbol(v);
                                    router.get('/strategy-lab', buildQueryPayload(v), { preserveScroll: true });
                                }}
                            >
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {instruments.map((inst) => (
                                        <SelectItem key={inst.id} value={inst.symbol}>
                                            {inst.symbol} · {inst.name} ({inst.exchange_code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-xs">{t('strategy_lab.field.strategy')}</Label>
                            <Select value={strategy} onValueChange={setStrategy}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {strategies.map((code) => (
                                        <SelectItem key={code} value={code}>
                                            {t(`strategy_lab.strategy.${code}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-xs">{t('strategy_lab.field.range')}</Label>
                            <div className="flex gap-1">
                                {ranges.map((r) => (
                                    <Button
                                        key={r}
                                        type="button"
                                        size="sm"
                                        variant={range === r ? 'default' : 'outline'}
                                        className="h-8 flex-1 text-xs"
                                        onClick={() => setRange(r)}
                                    >
                                        {r}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-xs">
                                {t('strategy_lab.field.initial_capital')} ({ccy})
                            </Label>
                            <Input
                                type="number"
                                step="any"
                                value={initialCapital}
                                onChange={(e) => setInitialCapital(e.target.value)}
                                className="h-8 font-mono text-xs"
                            />
                        </div>
                    </div>

                    {/* Dynamic Strategy Parameters & Frictions */}
                    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {strategy === 'sma_crossover' && (
                            <>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.fast_period')}</Label>
                                    <Input type="number" value={fastPeriod} onChange={(e) => setFastPeriod(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.slow_period')}</Label>
                                    <Input type="number" value={slowPeriod} onChange={(e) => setSlowPeriod(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                            </>
                        )}
                        {strategy === 'rsi_mean_reversion' && (
                            <>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.rsi_period')}</Label>
                                    <Input type="number" value={rsiPeriod} onChange={(e) => setRsiPeriod(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.oversold')}</Label>
                                    <Input type="number" value={oversold} onChange={(e) => setOversold(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.overbought')}</Label>
                                    <Input type="number" value={overbought} onChange={(e) => setOverbought(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                            </>
                        )}
                        {strategy === 'macd_momentum' && (
                            <>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.fast_ema')}</Label>
                                    <Input type="number" value={fastEma} onChange={(e) => setFastEma(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.slow_ema')}</Label>
                                    <Input type="number" value={slowEma} onChange={(e) => setSlowEma(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.signal_ema')}</Label>
                                    <Input type="number" value={signalEma} onChange={(e) => setSignalEma(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                            </>
                        )}
                        {strategy === 'donchian_breakout' && (
                            <>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.entry_lookback')}</Label>
                                    <Input type="number" value={entryLookback} onChange={(e) => setEntryLookback(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">{t('strategy_lab.field.exit_lookback')}</Label>
                                    <Input type="number" value={exitLookback} onChange={(e) => setExitLookback(e.target.value)} className="h-8 font-mono text-xs" />
                                </div>
                            </>
                        )}

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('strategy_lab.field.allocation_pct')}</Label>
                            <Input type="number" step="0.05" min="0.1" max="1" value={allocationPct} onChange={(e) => setAllocationPct(e.target.value)} className="h-8 font-mono text-xs" />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('strategy_lab.field.buy_fee_pct')}</Label>
                            <Input type="number" step="0.0001" min="0" max="0.05" value={buyFeePct} onChange={(e) => setBuyFeePct(e.target.value)} className="h-8 font-mono text-xs" />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('strategy_lab.field.slippage_pct')}</Label>
                            <Input type="number" step="0.0001" min="0" max="0.05" value={slippagePct} onChange={(e) => setSlippagePct(e.target.value)} className="h-8 font-mono text-xs" />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('strategy_lab.field.stop_loss_pct')}</Label>
                            <Input type="number" step="0.01" min="0.01" max="0.5" placeholder="0.08" value={stopLossPct} onChange={(e) => setStopLossPct(e.target.value)} className="h-8 font-mono text-xs" />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('strategy_lab.field.take_profit_pct')}</Label>
                            <Input type="number" step="0.01" min="0.02" max="2" placeholder="0.20" value={takeProfitPct} onChange={(e) => setTakeProfitPct(e.target.value)} className="h-8 font-mono text-xs" />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" size="sm">
                                <Play className="mr-1.5 h-3.5 w-3.5" />
                                {t('strategy_lab.action.run')}
                            </Button>
                            {backtest && (
                                <Link
                                    href={`/stocks/${backtest.instrument.exchange_code.toLowerCase()}/${backtest.instrument.symbol.toLowerCase()}`}
                                    className="text-primary text-xs hover:underline"
                                >
                                    {backtest.instrument.exchange_code}:{backtest.instrument.symbol} (lot: {backtest.instrument.lot_size})
                                </Link>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <Input
                                value={snapshotName}
                                onChange={(e) => setSnapshotName(e.target.value)}
                                placeholder={t('strategy_lab.field.snapshot_name')}
                                className="h-8 w-[190px] text-xs"
                            />
                            <Button type="button" size="sm" variant="outline" onClick={saveSnapshot}>
                                <BookmarkPlus className="mr-1.5 h-3.5 w-3.5" />
                                {t('strategy_lab.action.save')}
                            </Button>
                        </div>
                    </div>
                </form>

                {!backtest || !s || backtest.trading_days === 0 ? (
                    <EmptyState
                        icon={FlaskConical}
                        title={t('strategy_lab.trades.empty')}
                        description={t('strategy_lab.subtitle')}
                        actionLabel={t('nav.stock_explorer')}
                        actionHref="/stocks"
                    />
                ) : (
                    <>
                        {/* KPI Strip */}
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label={t('strategy_lab.kpi.total_return')}
                                value={formatPct(s.total_return_pct, locale)}
                                subtext={`${t('strategy_lab.kpi.buy_hold')}: ${formatPct(s.buy_hold_return_pct, locale)}`}
                            />
                            <MetricCard
                                label={t('strategy_lab.kpi.final_equity')}
                                value={`${ccy} ${formatCompact(Number(s.final_equity), locale)}`}
                                subtext={`Net: ${ccy} ${formatCompact(Number(s.net_profit), locale)}`}
                            />
                            <MetricCard
                                label={t('strategy_lab.kpi.max_drawdown')}
                                value={formatPct(s.max_drawdown_pct, locale)}
                                subtext={`${t('strategy_lab.kpi.sharpe')}: ${s.sharpe_ratio !== null ? formatNumber(s.sharpe_ratio, locale, 2, 2) : '—'}`}
                            />
                            <MetricCard
                                label={t('strategy_lab.kpi.win_rate')}
                                value={s.win_rate_pct !== null ? formatPct(s.win_rate_pct, locale) : '—'}
                                subtext={`${s.win_count}W / ${s.loss_count}L · ${t('strategy_lab.kpi.profit_factor')}: ${s.profit_factor !== null ? formatNumber(s.profit_factor, locale, 2, 2) : '—'}`}
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <MetricCard label={t('strategy_lab.kpi.exposure')} value={formatPct(s.exposure_time_pct, locale)} />
                            <MetricCard
                                label={t('strategy_lab.kpi.fees_paid')}
                                value={`${ccy} ${formatCompact(Number(s.total_fees_paid), locale)}`}
                            />
                            <MetricCard
                                label="CAGR / Sortino"
                                value={`${s.cagr_pct !== null ? formatPct(s.cagr_pct, locale) : '—'} / ${s.sortino_ratio !== null ? formatNumber(s.sortino_ratio, locale, 2, 2) : '—'}`}
                            />
                        </div>

                        {/* Equity vs Buy-Hold & Drawdown Chart */}
                        <section className="bg-card border-border rounded-md border p-4">
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-semibold">
                                    {t('strategy_lab.chart.title')} ({backtest.start_date} → {backtest.end_date})
                                </h2>
                                <div className="flex gap-1">
                                    <Button
                                        size="sm"
                                        variant={chartMode === 'equity' ? 'default' : 'outline'}
                                        className="h-7 px-2.5 text-xs"
                                        onClick={() => setChartMode('equity')}
                                    >
                                        {t('strategy_lab.chart.mode_equity')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={chartMode === 'drawdown' ? 'default' : 'outline'}
                                        className="h-7 px-2.5 text-xs"
                                        onClick={() => setChartMode('drawdown')}
                                    >
                                        {t('strategy_lab.chart.mode_drawdown')}
                                    </Button>
                                </div>
                            </div>
                            <StrategyEquityChart data={backtest.equity_curve} currency={ccy} mode={chartMode} />
                        </section>

                        {/* Open Position Banner */}
                        {backtest.open_position && (
                            <div className="bg-card border-border flex flex-wrap items-center justify-between gap-2 rounded-md border px-4 py-2.5 text-xs">
                                <div>
                                    <span className="font-semibold">{t('strategy_lab.open_pos')}: </span>
                                    <span className="font-mono">
                                        {formatNumber(backtest.open_position.shares, locale, 0, 0)} sh @ {formatPrice(backtest.open_position.entry_price, ccy, locale)} ({backtest.open_position.entry_date})
                                    </span>
                                </div>
                                <div className="font-mono">
                                    <Money value={backtest.open_position.unrealized_pnl} currency={ccy} mode="money" /> (
                                    {formatPct(backtest.open_position.unrealized_pct, locale)})
                                </div>
                            </div>
                        )}

                        {/* Trades Log */}
                        <section className="bg-card border-border rounded-md border p-4">
                            <h2 className="mb-3 text-sm font-semibold">
                                {t('strategy_lab.trades.title')} ({backtest.trades.length})
                            </h2>
                            {backtest.trades.length === 0 ? (
                                <p className="text-muted-foreground text-xs">{t('strategy_lab.trades.empty')}</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b text-left">
                                                <th className="py-1.5 pr-2">#</th>
                                                <th className="py-1.5 pr-2">{t('strategy_lab.trades.col_signal')}</th>
                                                <th className="py-1.5 pr-2">{t('strategy_lab.trades.col_entry')}</th>
                                                <th className="py-1.5 pr-2">{t('strategy_lab.trades.col_exit')}</th>
                                                <th className="py-1.5 pr-2 text-right">{t('strategy_lab.trades.col_shares')}</th>
                                                <th className="py-1.5 pr-2 text-right">{t('strategy_lab.trades.col_prices')}</th>
                                                <th className="py-1.5 pr-2 text-right">{t('strategy_lab.trades.col_fees')}</th>
                                                <th className="py-1.5 pr-2 text-right">{t('strategy_lab.trades.col_net_pnl')}</th>
                                                <th className="py-1.5">{t('strategy_lab.trades.col_reason')}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {backtest.trades.map((tr) => (
                                                <tr key={tr.trade_no}>
                                                    <td className="py-1.5 pr-2 font-mono">{tr.trade_no}</td>
                                                    <td className="py-1.5 pr-2 font-mono">{tr.signal_date}</td>
                                                    <td className="py-1.5 pr-2 font-mono">{tr.entry_date}</td>
                                                    <td className="py-1.5 pr-2 font-mono">{tr.exit_date} ({tr.holding_days}d)</td>
                                                    <td className="py-1.5 pr-2 text-right font-mono">{formatNumber(tr.shares, locale, 0, 0)}</td>
                                                    <td className="py-1.5 pr-2 text-right font-mono">
                                                        {formatPrice(tr.entry_price, ccy, locale)} → {formatPrice(tr.exit_price, ccy, locale)}
                                                    </td>
                                                    <td className="py-1.5 pr-2 text-right font-mono">
                                                        <Money value={tr.total_fees} currency={ccy} mode="money" />
                                                    </td>
                                                    <td
                                                        className={`py-1.5 pr-2 text-right font-mono font-medium ${
                                                            Number(tr.net_pnl) >= 0
                                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                                : 'text-rose-600 dark:text-rose-400'
                                                        }`}
                                                    >
                                                        <Money value={tr.net_pnl} currency={ccy} mode="money" /> ({formatPct(tr.return_pct, locale)})
                                                    </td>
                                                    <td className="py-1.5">
                                                        <span className="bg-muted rounded px-1.5 py-0.5 text-[10px]">
                                                            {t(`strategy_lab.reason.${tr.exit_reason}`)}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </>
                )}

                {/* Saved Snapshots */}
                <section className="bg-card border-border rounded-md border p-4">
                    <h2 className="mb-3 text-sm font-semibold">{t('strategy_lab.saved.title')}</h2>
                    {savedRuns.length === 0 ? (
                        <p className="text-muted-foreground text-xs">{t('strategy_lab.saved.empty')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-border text-muted-foreground border-b text-left">
                                        <th className="py-1.5 pr-3">Snapshot</th>
                                        <th className="py-1.5 pr-3">{t('strategy_lab.field.symbol')}</th>
                                        <th className="py-1.5 pr-3">{t('strategy_lab.field.strategy')}</th>
                                        <th className="py-1.5 pr-3 text-right">{t('strategy_lab.kpi.total_return')}</th>
                                        <th className="py-1.5 pr-3 text-right">{t('strategy_lab.kpi.max_drawdown')}</th>
                                        <th className="py-1.5 pr-3 text-right">Trades</th>
                                        <th className="py-1.5 text-right" />
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {savedRuns.map((run) => (
                                        <tr key={run.id}>
                                            <td className="py-1.5 pr-3">
                                                <div className="font-medium">{run.name || `${run.symbol} · ${run.range_code}`}</div>
                                                <div className="text-muted-foreground text-[11px]">
                                                    {run.executed_at ? formatDateTime(run.executed_at, locale) : ''}
                                                </div>
                                            </td>
                                            <td className="py-1.5 pr-3 font-mono">{run.exchange_code}:{run.symbol}</td>
                                            <td className="py-1.5 pr-3">{t(`strategy_lab.strategy.${run.strategy_code}`)}</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">{formatPct(run.total_return_pct, locale)}</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">{formatPct(run.max_drawdown_pct, locale)}</td>
                                            <td className="py-1.5 pr-3 text-right font-mono">{run.trades_count}</td>
                                            <td className="py-1.5 text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 px-2 text-xs"
                                                        onClick={() =>
                                                            router.get('/strategy-lab', {
                                                                symbol: run.symbol,
                                                                strategy: run.strategy_code,
                                                                range: run.range_code,
                                                            })
                                                        }
                                                    >
                                                        {t('strategy_lab.saved.load')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-muted-foreground hover:text-destructive h-7 px-2"
                                                        onClick={() =>
                                                            router.delete(`/strategy-lab/runs/${run.id}`, { preserveScroll: true })
                                                        }
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <p className="text-muted-foreground border-border border-t pt-3 text-[11px]">
                    {t('strategy_lab.disclaimer')}
                </p>
            </div>
        </AppLayout>
    );
}
