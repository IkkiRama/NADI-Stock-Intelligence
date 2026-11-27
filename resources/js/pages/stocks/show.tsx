import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MarketStatusBadge } from '@/components/nadi/market-status-badge';
import { ModuleEmptyState } from '@/components/nadi/module-empty-state';
import { Money } from '@/components/nadi/money';
import {
    type BenchmarkOverlayData,
    type IndicatorSeriesData,
    PriceChart,
} from '@/components/nadi/price-chart';
import { PositionSizerCard } from '@/components/nadi/position-sizer-card';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Sparkline } from '@/components/nadi/sparkline';
import { type SignalPlan } from '@/pages/intelligence/entry-exit';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatDate, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type MarketClockStatus, type OhlcvBar } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Info, MinusCircle, ShieldAlert, Star, Target, XCircle } from 'lucide-react';
import { useState } from 'react';

interface StockShowProps {
    instrument: {
        id: number;
        symbol: string;
        name: string;
        type: string;
        currency: string;
        sector: string | null;
        industry: string | null;
        country_code: string;
        lot_size: number;
        lot_size_verified: boolean;
        exchange: {
            code: string;
            name: string;
            timezone: string;
            clock: MarketClockStatus;
        };
        quote: {
            close: string;
            prev_close: string | null;
            change: string | null;
            change_pct: string | null;
            volume: number;
            value_traded: string | null;
            open: string | null;
            high: string | null;
            low: string | null;
            as_of: string;
            is_demo: boolean;
            source: string;
        } | null;
    };
    stats: {
        high_52w: string | null;
        low_52w: string | null;
        avg_volume_20d: string | null;
        return_1m: string | null;
        return_3m: string | null;
        return_6m: string | null;
        return_ytd: string | null;
        return_1y: string | null;
    };
    bars: OhlcvBar[];
    indicatorSeries?: IndicatorSeriesData;
    benchmarkOverlay?: BenchmarkOverlayData | null;
    fundamentals: {
        as_of_date: string;
        period_label: string;
        statement_currency: string;
        market_cap: string | null;
        market_cap_idr: string | null;
        eps: string | null;
        per: string | null;
        pbv: string | null;
        roe: string | null;
        roa: string | null;
        der: string | null;
        current_ratio: string | null;
        net_margin: string | null;
        revenue_growth_yoy: string | null;
        earnings_growth_yoy: string | null;
        ocf: string | null;
        fcf: string | null;
        dividend_yield: string | null;
        dividend_payout_ratio: string | null;
        is_financial_sector: boolean;
        missing_fields: string[];
        is_demo: boolean;
        source: string;
    } | null;
    statements: Array<{
        period_label: string;
        period_end_date: string;
        published_at: string;
        currency: string;
        revenue: string | null;
        net_income: string | null;
        total_assets: string | null;
        total_equity: string | null;
        operating_cash_flow: string | null;
        free_cash_flow: string | null;
        is_demo: boolean;
    }>;
    technicals: {
        trade_date: string;
        sma_20: string | null;
        sma_50: string | null;
        sma_200: string | null;
        ema_20: string | null;
        rsi_14: string | null;
        macd_line: string | null;
        macd_signal: string | null;
        macd_hist: string | null;
        atr_14: string | null;
        obv: number | null;
        volatility_20d: string | null;
        relative_strength_1m: string | null;
    } | null;
    score: {
        as_of_date: string;
        overall_score: string;
        fundamentals_score: string | null;
        valuation_score: string | null;
        technical_score: string | null;
        risk_score: string | null;
        catalysts_score: string | null;
        sector_percentile: string | null;
        data_coverage: string;
        reasons: Array<{ type: 'positive' | 'negative' | 'neutral'; label: string; detail: string }>;
        missing_indicators: string[];
        weights_used: Record<string, number>;
        calculation_version: string;
        is_demo: boolean;
    } | null;
    scoreHistory: Array<{ date: string; overall_score: string }>;
    corporateActions: Array<{
        id: number;
        action_type: string;
        ex_date: string;
        payment_date: string | null;
        declared_currency: string | null;
        cash_amount: string | null;
        ratio_numerator: string | null;
        ratio_denominator: string | null;
        notes: string | null;
        is_demo: boolean;
    }>;
    safety: {
        as_of_date: string;
        risk_score: string | null;
        risk_category: string;
        safeguard_triggered: boolean;
        triggered_rules: Array<{ code: string; severity: string; points: number; title: string; evidence: string }>;
        data_confidence: string;
        missing_factors: string[];
        calculation_version: string;
        is_demo: boolean;
        regulatory: {
            is_suspended: boolean;
            has_exchange_warning: boolean;
            has_special_monitoring: boolean;
            free_float_pct: string | null;
            top_holder_pct: string | null;
            status_notes: string | null;
            source_note: string | null;
        } | null;
    } | null;
    safetyHistory: Array<{ date: string; risk_score: string | null; risk_category: string }>;
    signalsByHorizon: Record<string, SignalPlan | null>;
    signalHistory: Array<{
        id: number;
        signal_date: string;
        stance: string;
        entry_low: string | null;
        stop_loss: string | null;
        target_1: string | null;
        net_rr: string | null;
        safeguard_suppressed: boolean;
    }>;
    tradingCosts: {
        buy_fee_pct: string;
        sell_fee_pct: string;
        slippage_pct: string;
    };
    holdingStatus?: {
        cost_basis_method: string;
        currency: string;
        quantity: string;
        avg_cost_per_share: string;
        total_cost: string;
        market_value: string | null;
        unrealized_pnl: string | null;
        unrealized_pnl_pct: string | null;
        accounts: Array<{ account_id: number; account_name: string; quantity: string; cost_per_share: string }>;
    } | null;
    inWatchlist: boolean;
}

export default function StockShow({
    instrument,
    stats,
    bars,
    indicatorSeries,
    benchmarkOverlay,
    fundamentals,
    statements,
    technicals,
    score,
    scoreHistory,
    corporateActions,
    safety,
    safetyHistory,
    signalsByHorizon,
    signalHistory,
    tradingCosts,
    holdingStatus,
    inWatchlist,
}: StockShowProps) {
    const { t, locale } = useT();
    const [signalHorizon, setSignalHorizon] = useState<'short_term' | 'swing' | 'long_term'>('swing');
    const activeSignal = signalsByHorizon?.[signalHorizon] ?? null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.stock_explorer'), href: '/stocks' },
        { title: `${instrument.exchange.code}:${instrument.symbol}`, href: `/stocks/${instrument.exchange.code}/${instrument.symbol}` },
    ];

    const toggleWatchlist = () => {
        if (inWatchlist) {
            router.delete(`/watchlist/items/${instrument.id}`, { preserveScroll: true });
        } else {
            router.post('/watchlist/items', { instrument_id: instrument.id }, { preserveScroll: true });
        }
    };

    const returnStrip = [
        { label: t('markets.return_1m'), val: stats.return_1m },
        { label: t('markets.return_3m'), val: stats.return_3m },
        { label: t('markets.return_6m'), val: stats.return_6m },
        { label: t('markets.return_ytd'), val: stats.return_ytd },
        { label: t('markets.return_1y'), val: stats.return_1y },
    ];

    const asidePanel = (
        <div className="space-y-4">
            <div className="bg-card border-border rounded-md border p-4">
                <h2 className="mb-3 text-xs font-semibold tracking-wider uppercase">{t('stocks.detail.stats')}</h2>
                <dl className="divide-border divide-y text-xs">
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">Market Cap</dt>
                        <dd className="num font-medium">{formatCompact(fundamentals?.market_cap, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.open')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.open, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.high')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.high, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.low')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.low, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.prev_close')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.prev_close, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('markets.high_52w')}</dt>
                        <dd className="num font-medium">{formatPrice(stats.high_52w, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('markets.low_52w')}</dt>
                        <dd className="num font-medium">{formatPrice(stats.low_52w, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.volume')}</dt>
                        <dd className="num font-medium">{formatCompact(instrument.quote?.volume, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.avg_vol_20d')}</dt>
                        <dd className="num font-medium">{formatCompact(stats.avg_volume_20d, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.value_traded')}</dt>
                        <dd className="num font-medium">{formatCompact(instrument.quote?.value_traded, locale)}</dd>
                    </div>
                </dl>
            </div>

            <div className="bg-card border-border rounded-md border p-4">
                <h2 className="mb-3 text-xs font-semibold tracking-wider uppercase">{t('stocks.detail.profile')}</h2>
                <dl className="divide-border divide-y text-xs">
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.exchange')}</dt>
                        <dd className="font-medium">{instrument.exchange.code}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.sector')}</dt>
                        <dd className="font-medium">{instrument.sector ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.currency')}</dt>
                        <dd className="font-medium">{instrument.currency}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.country')}</dt>
                        <dd className="font-medium">{instrument.country_code}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.lot_size')}</dt>
                        <dd className="num text-right font-medium">
                            {instrument.lot_size}
                            {!instrument.lot_size_verified && (
                                <span className="text-muted-foreground block text-[10px] font-normal">
                                    {t('stocks.detail.lot_unverified')}
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>
            </div>

            <div className="bg-card border-border rounded-md border p-4">
                <div className="mb-3 flex items-center justify-between gap-2">
                    <h2 className="text-xs font-semibold tracking-wider uppercase">{t('stocks.detail.holding_status')}</h2>
                    <Link href="/portfolio" className="text-muted-foreground hover:text-foreground text-[11px] underline-offset-4 hover:underline">
                        {t('app.view_all')}
                    </Link>
                </div>
                {!holdingStatus ? (
                    <p className="text-muted-foreground text-xs">{t('heatmap.empty_portfolio')}</p>
                ) : (
                    <dl className="divide-border divide-y text-xs">
                        <div className="flex justify-between py-2">
                            <dt className="text-muted-foreground">Jumlah Saham</dt>
                            <dd className="num font-semibold">{formatNumber(holdingStatus.quantity, locale, 0, 2)}</dd>
                        </div>
                        <div className="flex justify-between py-2">
                            <dt className="text-muted-foreground">Avg Cost ({holdingStatus.cost_basis_method})</dt>
                            <dd className="num font-medium">{formatPrice(holdingStatus.avg_cost_per_share, holdingStatus.currency, locale)}</dd>
                        </div>
                        <div className="flex justify-between py-2">
                            <dt className="text-muted-foreground">Market Value</dt>
                            <dd className="num font-medium">{formatPrice(holdingStatus.market_value, holdingStatus.currency, locale)}</dd>
                        </div>
                        <div className="flex justify-between py-2">
                            <dt className="text-muted-foreground">Unrealized P&L</dt>
                            <dd>
                                <ChangeBadge
                                    change={holdingStatus.unrealized_pnl}
                                    changePct={holdingStatus.unrealized_pnl_pct}
                                    currency={holdingStatus.currency}
                                    showValue
                                />
                            </dd>
                        </div>
                    </dl>
                )}
            </div>
        </div>
    );

    const scoreComponents = score
        ? [
              { key: 'fundamentals', val: score.fundamentals_score, weight: score.weights_used?.fundamentals ?? 30 },
              { key: 'valuation', val: score.valuation_score, weight: score.weights_used?.valuation ?? 25 },
              { key: 'technical', val: score.technical_score, weight: score.weights_used?.technical ?? 20 },
              { key: 'risk', val: score.risk_score, weight: score.weights_used?.risk ?? 15 },
              { key: 'catalysts', val: score.catalysts_score, weight: score.weights_used?.catalysts ?? 10 },
          ]
        : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs} aside={asidePanel}>
            <Head title={`${instrument.symbol} (${instrument.exchange.code}) — ${instrument.name}`} />

            <div className="space-y-5">
                <div className="bg-card border-border flex flex-wrap items-start justify-between gap-4 rounded-md border p-4">
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{instrument.symbol}</h1>
                            <span className="border-border bg-muted rounded border px-1.5 py-0.5 text-[11px] font-semibold">
                                {instrument.exchange.code}
                            </span>
                            <MarketStatusBadge status={instrument.exchange.clock} />
                        </div>
                        <p className="text-muted-foreground text-sm">{instrument.name}</p>
                        {instrument.quote && (
                            <DataFreshness asOf={instrument.quote.as_of} source={instrument.quote.source} isDemo={instrument.quote.is_demo} />
                        )}
                    </div>

                    <div className="flex flex-col items-end gap-2">
                        {instrument.quote && (
                            <div className="text-right">
                                <div className="num text-2xl font-semibold">
                                    <Money value={instrument.quote.close} currency={instrument.currency} />
                                </div>
                                <ChangeBadge
                                    change={instrument.quote.change}
                                    changePct={instrument.quote.change_pct}
                                    currency={instrument.currency}
                                    showValue
                                    pill
                                    className="mt-1"
                                />
                            </div>
                        )}

                        <Button type="button" size="sm" variant={inWatchlist ? 'secondary' : 'outline'} onClick={toggleWatchlist} className="gap-1.5 text-xs">
                            <Star className={cn('h-3.5 w-3.5', inWatchlist && 'fill-amber-400 text-amber-500')} />
                            <span>{inWatchlist ? t('stocks.watchlist.remove') : t('stocks.watchlist.add')}</span>
                        </Button>
                    </div>
                </div>

                <PriceChart
                    bars={bars}
                    indicatorSeries={indicatorSeries}
                    corporateActions={corporateActions}
                    benchmarkOverlay={benchmarkOverlay}
                    priceLevels={
                        activeSignal
                            ? {
                                  support: activeSignal.support_level ? Number(activeSignal.support_level) : null,
                                  resistance: activeSignal.resistance_level ? Number(activeSignal.resistance_level) : null,
                                  entryLow: activeSignal.entry_low ? Number(activeSignal.entry_low) : null,
                                  entryHigh: activeSignal.entry_high ? Number(activeSignal.entry_high) : null,
                                  stopLoss: activeSignal.stop_loss ? Number(activeSignal.stop_loss) : null,
                                  target1: activeSignal.target_1 ? Number(activeSignal.target_1) : null,
                                  target2: activeSignal.target_2 ? Number(activeSignal.target_2) : null,
                              }
                            : null
                    }
                    signalMarkers={signalHistory.map((h) => ({
                        date: h.signal_date,
                        stance: h.stance,
                        label: h.stance === 'actionable_setup' ? 'BUY?' : h.stance === 'suppressed_by_safety' ? 'SAFE!' : 'SIG',
                    }))}
                    height={390}
                />

                <div className="bg-card border-border grid grid-cols-5 gap-2 rounded-md border p-3">
                    {returnStrip.map((item) => (
                        <div key={item.label}>
                            <span className="text-muted-foreground block text-[11px]">{item.label}</span>
                            <span
                                className={cn(
                                    'num text-xs font-semibold',
                                    item.val === null
                                        ? 'text-muted-foreground'
                                        : Number(item.val) >= 0
                                          ? 'text-[var(--nadi-gain-ink)]'
                                          : 'text-[var(--nadi-loss-ink)]',
                                )}
                            >
                                {formatPct(item.val, locale)}
                            </span>
                        </div>
                    ))}
                </div>

                {/* NADI Stock Score */}
                <section className="bg-card border-border rounded-md border p-4 space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-sm font-semibold">{t('stocks.score.title')}</h2>
                                {score?.is_demo && <DemoBadge />}
                                {score && (
                                    <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 font-mono text-[10px]">
                                        {score.calculation_version}
                                    </span>
                                )}
                            </div>
                            <p className="text-muted-foreground mt-0.5 text-xs">{t('stocks.score.disclaimer')}</p>
                        </div>

                        {score && (
                            <div className="flex items-center gap-4">
                                {scoreHistory.length > 1 && (
                                    <Sparkline values={scoreHistory.map((h) => h.overall_score)} positive width={84} height={28} />
                                )}
                                <div className="border-border bg-muted/40 rounded-md border px-3 py-1.5 text-right">
                                    <div className="num text-xl font-bold">{formatNumber(score.overall_score, locale, 1, 1)}</div>
                                    <div className="text-muted-foreground text-[10px]">/ 100</div>
                                </div>
                            </div>
                        )}
                    </div>

                    {!score ? (
                        <EmptyState title={t('app.insufficient_data')} />
                    ) : (
                        <>
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="space-y-2.5">
                                    {scoreComponents.map((c) => {
                                        const val = c.val !== null ? Number(c.val) : null;
                                        return (
                                            <div key={c.key} className="space-y-1">
                                                <div className="flex items-center justify-between text-xs">
                                                    <span>
                                                        {t(`stocks.score.comp.${c.key}`)}{' '}
                                                        <span className="text-muted-foreground">({c.weight}%)</span>
                                                    </span>
                                                    <span className="num font-semibold">{val !== null ? formatNumber(val, locale, 1, 1) : '—'}</span>
                                                </div>
                                                <div className="bg-muted h-2 w-full overflow-hidden rounded">
                                                    <div
                                                        className="bg-primary h-full rounded transition-all"
                                                        style={{ width: `${val !== null ? Math.min(100, Math.max(0, val)) : 0}%` }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                    <div className="text-muted-foreground flex flex-wrap gap-4 pt-1 text-[11px]">
                                        <span>
                                            {t('stocks.score.coverage')}: <strong className="num">{score.data_coverage}%</strong>
                                        </span>
                                        {score.sector_percentile && (
                                            <span>
                                                {t('stocks.score.sector_pct')}: <strong className="num">{score.sector_percentile}%</strong>
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <h3 className="text-muted-foreground text-xs font-semibold">{t('stocks.score.reasons')}</h3>
                                    <ul className="space-y-1.5 text-xs">
                                        {score.reasons.map((r, i) => (
                                            <li key={i} className="flex items-start gap-2">
                                                {r.type === 'positive' ? (
                                                    <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--nadi-gain-ink)]" />
                                                ) : r.type === 'negative' ? (
                                                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--nadi-loss-ink)]" />
                                                ) : (
                                                    <MinusCircle className="text-muted-foreground mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                )}
                                                <span>
                                                    <strong className="font-medium">{r.label}</strong>{' '}
                                                    <span className="text-muted-foreground num">({r.detail})</span>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    {score.missing_indicators.length > 0 && (
                                        <p className="text-muted-foreground text-[11px]">
                                            {t('stocks.score.missing')}: {score.missing_indicators.join(', ')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </section>

                {/* Fundamentals & Technicals Grid */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">{t('stocks.fundamentals.title')}</h2>
                            {fundamentals && (
                                <span className="text-muted-foreground text-xs">{fundamentals.period_label}</span>
                            )}
                        </div>

                        {!fundamentals ? (
                            <EmptyState title={t('app.insufficient_data')} />
                        ) : (
                            <>
                                {fundamentals.is_financial_sector && (
                                    <div className="border-border bg-muted/40 text-muted-foreground flex items-start gap-2 rounded border p-2 text-[11px]">
                                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        <span>{t('stocks.fundamentals.financial_note')}</span>
                                    </div>
                                )}
                                {fundamentals.statement_currency !== instrument.currency && (
                                    <div className="border-border bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)] flex items-start gap-2 rounded border p-2 text-[11px]">
                                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        <span>
                                            {t('stocks.fundamentals.cross_ccy_note', {
                                                stmt_ccy: fundamentals.statement_currency,
                                                inst_ccy: instrument.currency,
                                            })}
                                        </span>
                                    </div>
                                )}

                                <dl className="divide-border grid grid-cols-2 gap-x-4 divide-y text-xs">
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">EPS ({instrument.currency})</dt>
                                        <dd className="num font-medium">{formatPrice(fundamentals.eps, instrument.currency, locale)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">PER</dt>
                                        <dd className="num font-medium">{fundamentals.per ? `${formatNumber(fundamentals.per, locale, 2, 2)}x` : '—'}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">PBV</dt>
                                        <dd className="num font-medium">{fundamentals.pbv ? `${formatNumber(fundamentals.pbv, locale, 2, 2)}x` : '—'}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">ROE</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.roe, locale, false)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">ROA</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.roa, locale, false)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Net Margin</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.net_margin, locale, false)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">DER</dt>
                                        <dd className="num font-medium">{fundamentals.der ? `${formatNumber(fundamentals.der, locale, 2, 2)}x` : '—'}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Current Ratio</dt>
                                        <dd className="num font-medium">
                                            {fundamentals.current_ratio ? `${formatNumber(fundamentals.current_ratio, locale, 2, 2)}x` : '—'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Rev Growth YoY</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.revenue_growth_yoy, locale)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Earn Growth YoY</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.earnings_growth_yoy, locale)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Div Yield</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.dividend_yield, locale, false)}</dd>
                                    </div>
                                    <div className="flex justify-between py-2">
                                        <dt className="text-muted-foreground">Payout Ratio</dt>
                                        <dd className="num font-medium">{formatPct(fundamentals.dividend_payout_ratio, locale, false)}</dd>
                                    </div>
                                </dl>
                            </>
                        )}
                    </section>

                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">{t('stocks.technicals.title')}</h2>
                            {technicals && <span className="text-muted-foreground num text-xs">{formatDate(technicals.trade_date, locale)}</span>}
                        </div>

                        {!technicals ? (
                            <EmptyState title={t('app.insufficient_data')} />
                        ) : (
                            <dl className="divide-border grid grid-cols-2 gap-x-4 divide-y text-xs">
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">SMA 20</dt>
                                    <dd className="num font-medium">{formatPrice(technicals.sma_20, instrument.currency, locale)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">SMA 50</dt>
                                    <dd className="num font-medium">{formatPrice(technicals.sma_50, instrument.currency, locale)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">SMA 200</dt>
                                    <dd className="num font-medium">{formatPrice(technicals.sma_200, instrument.currency, locale)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">EMA 20</dt>
                                    <dd className="num font-medium">{formatPrice(technicals.ema_20, instrument.currency, locale)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">RSI (14)</dt>
                                    <dd className="num font-medium">{formatNumber(technicals.rsi_14, locale, 2, 2)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">ATR (14)</dt>
                                    <dd className="num font-medium">{formatPrice(technicals.atr_14, instrument.currency, locale)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">MACD Line</dt>
                                    <dd className="num font-medium">{formatNumber(technicals.macd_line, locale, 2, 2)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">MACD Signal</dt>
                                    <dd className="num font-medium">{formatNumber(technicals.macd_signal, locale, 2, 2)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">Volatilitas 20H (Ann.)</dt>
                                    <dd className="num font-medium">{formatPct(technicals.volatility_20d, locale, false)}</dd>
                                </div>
                                <div className="flex justify-between py-2">
                                    <dt className="text-muted-foreground">Rel. Strength 1B</dt>
                                    <dd className="num font-medium">{formatPct(technicals.relative_strength_1m, locale)}</dd>
                                </div>
                            </dl>
                        )}
                    </section>
                </div>

                {/* Statements & Corporate Actions */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('stocks.statements.title')}</h2>
                        {statements.length === 0 ? (
                            <EmptyState title={t('app.insufficient_data')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="py-1.5 font-semibold">Periode</th>
                                            <th className="py-1.5 text-right font-semibold">Revenue</th>
                                            <th className="py-1.5 text-right font-semibold">Net Income</th>
                                            <th className="py-1.5 text-right font-semibold">Equity</th>
                                            <th className="py-1.5 text-right font-semibold">OCF</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {statements.map((s) => (
                                            <tr key={s.period_label}>
                                                <td className="py-2 font-medium">
                                                    {s.period_label} <span className="text-muted-foreground">({s.currency})</span>
                                                </td>
                                                <td className="num py-2 text-right">{formatCompact(s.revenue, locale)}</td>
                                                <td className="num py-2 text-right">{formatCompact(s.net_income, locale)}</td>
                                                <td className="num py-2 text-right">{formatCompact(s.total_equity, locale)}</td>
                                                <td className="num py-2 text-right">{formatCompact(s.operating_cash_flow, locale)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('stocks.actions.title')}</h2>
                        {corporateActions.length === 0 ? (
                            <EmptyState title={t('stocks.actions.empty')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="py-1.5 font-semibold">Ex-Date</th>
                                            <th className="py-1.5 font-semibold">Tipe</th>
                                            <th className="py-1.5 text-right font-semibold">Nilai / Rasio</th>
                                            <th className="py-1.5 font-semibold">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {corporateActions.map((a) => (
                                            <tr key={a.id}>
                                                <td className="num py-2">{formatDate(a.ex_date, locale)}</td>
                                                <td className="py-2 font-mono text-[11px]">{a.action_type}</td>
                                                <td className="num py-2 text-right">
                                                    {a.cash_amount
                                                        ? `${a.declared_currency ?? instrument.currency} ${formatPrice(a.cash_amount, a.declared_currency ?? instrument.currency, locale)}`
                                                        : a.ratio_numerator && a.ratio_denominator
                                                          ? `${Number(a.ratio_numerator)}:${Number(a.ratio_denominator)}`
                                                          : '—'}
                                                </td>
                                                <td className="text-muted-foreground py-2">{a.notes ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>

                {/* Phase 3: NADI Stock Safety Assessment */}
                <section className="bg-card border-border rounded-md border p-4 space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <ShieldAlert className="text-muted-foreground h-4 w-4" />
                                <h2 className="text-sm font-semibold">{t('stocks.section.safety')}</h2>
                                {safety && (
                                    <SafetyBadge
                                        category={safety.risk_category}
                                        score={safety.risk_score}
                                        safeguard={safety.safeguard_triggered}
                                    />
                                )}
                                {safety?.is_demo && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground mt-0.5 text-xs">{t('safety.disclaimer')}</p>
                        </div>

                        {safety && (
                            <div className="flex items-center gap-3">
                                {safetyHistory.length > 1 && (
                                    <Sparkline
                                        values={safetyHistory.filter((h) => h.risk_score !== null).map((h) => String(h.risk_score))}
                                        positive={false}
                                        width={80}
                                        height={26}
                                    />
                                )}
                                <div className="text-right text-xs">
                                    <div className="text-muted-foreground text-[11px]">{t('safety.confidence')}</div>
                                    <div className="num font-semibold">{formatNumber(safety.data_confidence, locale, 0, 0)}%</div>
                                </div>
                            </div>
                        )}
                    </div>

                    {!safety ? (
                        <EmptyState title={t('app.insufficient_data')} />
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 text-xs">
                            <div className="space-y-2">
                                <h3 className="text-muted-foreground font-semibold">{t('safety.triggered_rules')}</h3>
                                {safety.triggered_rules.length === 0 ? (
                                    <p className="text-[var(--nadi-gain-ink)] font-medium">{t('safety.no_rules')}</p>
                                ) : (
                                    <ul className="space-y-1.5">
                                        {safety.triggered_rules.map((r) => (
                                            <li key={r.code} className="border-border rounded border p-2">
                                                <div className="font-semibold">
                                                    {r.title}{' '}
                                                    <span className="text-muted-foreground num font-normal">(+{r.points} pts)</span>
                                                </div>
                                                <p className="text-muted-foreground mt-0.5 text-[11px]">{r.evidence}</p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <div className="space-y-2">
                                <h3 className="text-muted-foreground font-semibold">{t('safety.missing_factors')}</h3>
                                <p className="text-muted-foreground">{safety.missing_factors.join(', ') || '—'}</p>
                                {safety.regulatory?.status_notes && (
                                    <div className="border-[var(--nadi-loss-ink)]/30 bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] rounded border p-2">
                                        {safety.regulatory.status_notes}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </section>

                {/* Phase 3: Smart Entry & Exit Plan + Position Sizing */}
                <section className="bg-card border-border rounded-md border p-4 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Target className="text-muted-foreground h-4 w-4" />
                            <h2 className="text-sm font-semibold">{t('stocks.section.entry_exit')}</h2>
                            {activeSignal && (
                                <span
                                    className={cn(
                                        'rounded px-1.5 py-0.5 text-[11px] font-semibold',
                                        activeSignal.stance === 'actionable_setup'
                                            ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                                            : activeSignal.stance === 'suppressed_by_safety'
                                              ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                                              : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {t(`signals.stance.${activeSignal.stance}`)}
                                </span>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <div className="flex items-center gap-1 rounded-md border p-0.5">
                                {(['short_term', 'swing', 'long_term'] as const).map((h) => (
                                    <Button
                                        key={h}
                                        type="button"
                                        size="sm"
                                        variant={signalHorizon === h ? 'default' : 'ghost'}
                                        className="h-6 px-2 text-[11px]"
                                        onClick={() => setSignalHorizon(h)}
                                    >
                                        {t(`signals.horizon.${h}`)}
                                    </Button>
                                ))}
                            </div>
                            <Link
                                href={`/entry-exit?exchange=${instrument.exchange.code}&symbol=${instrument.symbol}&horizon=${signalHorizon}`}
                                className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline"
                            >
                                {t('app.view_all')}
                            </Link>
                        </div>
                    </div>

                    {!activeSignal ? (
                        <EmptyState title={t('app.insufficient_data')} />
                    ) : (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                                <div className="border-border rounded border p-2.5">
                                    <span className="text-muted-foreground block text-[11px]">{t('signals.entry_zone')}</span>
                                    <span className="num font-semibold">
                                        {activeSignal.entry_low && activeSignal.entry_high
                                            ? `${formatPrice(activeSignal.entry_low, instrument.currency, locale)} – ${formatPrice(activeSignal.entry_high, instrument.currency, locale)}`
                                            : '—'}
                                    </span>
                                </div>
                                <div className="border-border rounded border p-2.5">
                                    <span className="text-muted-foreground block text-[11px]">{t('signals.stop_loss')}</span>
                                    <span className="num text-[var(--nadi-loss-ink)] font-semibold">
                                        {formatPrice(activeSignal.stop_loss, instrument.currency, locale)}
                                    </span>
                                </div>
                                <div className="border-border rounded border p-2.5">
                                    <span className="text-muted-foreground block text-[11px]">
                                        {t('signals.target_1')} / {t('signals.target_2')}
                                    </span>
                                    <span className="num text-[var(--nadi-gain-ink)] font-semibold">
                                        {formatPrice(activeSignal.target_1, instrument.currency, locale)} /{' '}
                                        {formatPrice(activeSignal.target_2, instrument.currency, locale)}
                                    </span>
                                </div>
                                <div className="border-border rounded border p-2.5">
                                    <span className="text-muted-foreground block text-[11px]">{t('signals.net_rr')}</span>
                                    <span className="num font-semibold">
                                        {activeSignal.net_rr ? `${formatNumber(activeSignal.net_rr, locale, 2, 2)}x` : '—'}
                                    </span>
                                </div>
                            </div>

                            <ul className="space-y-1 text-xs">
                                {activeSignal.rationale.map((r, i) => (
                                    <li key={i} className="flex items-start gap-2">
                                        {r.status === 'pass' ? (
                                            <CheckCircle2 className="text-[var(--nadi-gain-ink)] mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        ) : r.status === 'fail' || r.status === 'warning' ? (
                                            <XCircle className="text-[var(--nadi-loss-ink)] mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        ) : (
                                            <Info className="text-muted-foreground mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        )}
                                        <span>{r.text}</span>
                                    </li>
                                ))}
                            </ul>

                            <PositionSizerCard
                                currency={instrument.currency}
                                lotSize={instrument.lot_size}
                                defaultEntry={activeSignal.entry_high ?? instrument.quote?.close}
                                defaultStopLoss={activeSignal.stop_loss}
                                defaultTarget={activeSignal.target_1}
                                tradingCosts={tradingCosts}
                            />
                        </>
                    )}
                </section>

                {/* Future Phases */}
                <div className="grid gap-4 md:grid-cols-2">
                    <ModuleEmptyState title={t('stocks.section.news')} description={t('planned.newsroom.desc')} phase={8} slug="newsroom" />
                    <ModuleEmptyState title={t('stocks.section.broker')} description={t('planned.broker_intelligence.desc')} phase={10} slug="broker-intelligence" />
                </div>
            </div>
        </AppLayout>
    );
}
