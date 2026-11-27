import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { Money } from '@/components/nadi/money';
import { PositionSizerCard } from '@/components/nadi/position-sizer-card';
import { type IndicatorSeriesData, PriceChart } from '@/components/nadi/price-chart';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type OhlcvBar } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, ShieldAlert, Target, XCircle } from 'lucide-react';

export interface SignalPlan {
    id: number;
    horizon: 'short_term' | 'swing' | 'long_term';
    signal_date: string;
    is_latest?: boolean;
    stance: 'actionable_setup' | 'watchlist_setup' | 'suppressed_by_safety' | 'no_setup' | 'insufficient_data';
    entry_low: string | null;
    entry_high: string | null;
    confirmation_price: string | null;
    stop_loss: string | null;
    invalidation_price: string | null;
    target_1: string | null;
    target_2: string | null;
    trailing_stop_atr_mult: string | null;
    support_level: string | null;
    resistance_level: string | null;
    gross_rr: string | null;
    net_rr: string | null;
    entry_stages: Array<{ stage: number; weight_pct: number; price: string; label: string }>;
    rationale: Array<{ status: string; text: string }>;
    invalidation_condition: string | null;
    safeguard_suppressed: boolean;
    calculation_version: string;
    is_demo: boolean;
}

interface EntryExitProps {
    instruments: Array<{ id: number; symbol: string; name: string; currency: string; exchange_code: string }>;
    selectedHorizon: 'short_term' | 'swing' | 'long_term';
    instrument: {
        id: number;
        symbol: string;
        name: string;
        currency: string;
        exchange_code: string;
        lot_size: number;
        close: string | null;
        change_pct: string | null;
        as_of: string | null;
        overall_score: string | null;
        is_demo: boolean;
    } | null;
    bars: OhlcvBar[];
    indicatorSeries?: IndicatorSeriesData;
    safety: {
        risk_score: string | null;
        risk_category: string;
        safeguard_triggered: boolean;
        triggered_rules: Array<{ code: string; title: string; evidence: string }>;
        data_confidence: string;
    } | null;
    signalsByHorizon: Record<string, SignalPlan | null>;
    signalHistory: SignalPlan[];
    tradingCosts: {
        buy_fee_pct: string;
        sell_fee_pct: string;
        slippage_pct: string;
    };
}

export default function EntryExit({
    instruments,
    selectedHorizon,
    instrument,
    bars,
    indicatorSeries,
    safety,
    signalsByHorizon,
    signalHistory,
    tradingCosts,
}: EntryExitProps) {
    const { t, locale } = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.entry_exit'), href: '/entry-exit' }];

    const activeSignal = signalsByHorizon[selectedHorizon] ?? null;

    const navigateSelection = (exchangeCode: string, symbol: string, horizon: string) => {
        router.get('/entry-exit', { exchange: exchangeCode, symbol, horizon }, { preserveScroll: true });
    };

    const priceLevels = activeSignal
        ? {
              support: activeSignal.support_level ? Number(activeSignal.support_level) : null,
              resistance: activeSignal.resistance_level ? Number(activeSignal.resistance_level) : null,
              entryLow: activeSignal.entry_low ? Number(activeSignal.entry_low) : null,
              entryHigh: activeSignal.entry_high ? Number(activeSignal.entry_high) : null,
              stopLoss: activeSignal.stop_loss ? Number(activeSignal.stop_loss) : null,
              target1: activeSignal.target_1 ? Number(activeSignal.target_1) : null,
              target2: activeSignal.target_2 ? Number(activeSignal.target_2) : null,
          }
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('entry_exit.title')} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <Target className="h-4 w-4" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">{t('entry_exit.title')}</h1>
                            <p className="text-muted-foreground text-xs">{t('entry_exit.subtitle')}</p>
                        </div>
                    </div>

                    {instrument && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={`${instrument.exchange_code}:${instrument.symbol}`}
                                onValueChange={(v) => {
                                    const [ex, sym] = v.split(':');
                                    navigateSelection(ex, sym, selectedHorizon);
                                }}
                            >
                                <SelectTrigger className="h-8 w-56 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {instruments.map((item) => (
                                        <SelectItem key={item.id} value={`${item.exchange_code}:${item.symbol}`}>
                                            {item.symbol} ({item.exchange_code}) — {item.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <div className="flex items-center gap-1 rounded-md border p-1">
                                {(['short_term', 'swing', 'long_term'] as const).map((h) => (
                                    <Button
                                        key={h}
                                        type="button"
                                        size="sm"
                                        variant={selectedHorizon === h ? 'default' : 'ghost'}
                                        className="h-6 px-2 text-xs"
                                        onClick={() => navigateSelection(instrument.exchange_code, instrument.symbol, h)}
                                    >
                                        {t(`signals.horizon.${h}`)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {instrument && (
                    <>
                        <div className="bg-card border-border flex flex-wrap items-center justify-between gap-4 rounded-md border p-4">
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link href={`/stocks/${instrument.exchange_code}/${instrument.symbol}`} className="text-base font-semibold hover:underline">
                                        {instrument.symbol} ({instrument.exchange_code})
                                    </Link>
                                    <SafetyBadge category={safety?.risk_category} score={safety?.risk_score} safeguard={safety?.safeguard_triggered} />
                                </div>
                                <p className="text-muted-foreground text-xs">{instrument.name}</p>
                                <DataFreshness asOf={instrument.as_of} isDemo={instrument.is_demo} />
                            </div>
                            <div className="text-right">
                                <div className="num text-xl font-bold">
                                    <Money value={instrument.close} currency={instrument.currency} />
                                </div>
                                <ChangeBadge changePct={instrument.change_pct} pill className="mt-1" />
                            </div>
                        </div>

                        {safety?.safeguard_triggered && (
                            <div className="border-[var(--nadi-loss-ink)]/40 bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] flex items-start gap-2.5 rounded-md border p-3 text-xs">
                                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                                <div>
                                    <strong className="font-semibold">{t('signals.stance.suppressed_by_safety')}</strong>
                                    <p className="mt-0.5">{t('safety.disclaimer')}</p>
                                </div>
                            </div>
                        )}

                        <PriceChart bars={bars} indicatorSeries={indicatorSeries} priceLevels={priceLevels} height={350} />

                        {activeSignal && (
                            <section className="bg-card border-border rounded-md border p-4 space-y-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-sm font-semibold">{t('stocks.section.entry_exit')}</h2>
                                        <span
                                            className={cn(
                                                'rounded px-2 py-0.5 text-[11px] font-semibold',
                                                activeSignal.stance === 'actionable_setup'
                                                    ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                                                    : activeSignal.stance === 'suppressed_by_safety'
                                                      ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                                                      : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {t(`signals.stance.${activeSignal.stance}`)}
                                        </span>
                                    </div>
                                    <span className="text-muted-foreground num text-xs">
                                        {formatDate(activeSignal.signal_date, locale)} · {activeSignal.calculation_version}
                                    </span>
                                </div>

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

                                {activeSignal.entry_stages && activeSignal.entry_stages.length > 0 && (
                                    <div className="grid gap-2 sm:grid-cols-3 text-xs">
                                        {activeSignal.entry_stages.map((st) => (
                                            <div key={st.stage} className="border-border bg-muted/30 flex items-center justify-between rounded border px-3 py-2">
                                                <div>
                                                    <span className="font-semibold">
                                                        Stage {st.stage} ({st.weight_pct}%)
                                                    </span>
                                                    <span className="text-muted-foreground block text-[11px]">{st.label}</span>
                                                </div>
                                                <span className="num font-semibold">{formatPrice(st.price, instrument.currency, locale)}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="space-y-1.5 text-xs">
                                    <h3 className="text-muted-foreground font-semibold">{t('signals.rationale')}</h3>
                                    <ul className="space-y-1">
                                        {activeSignal.rationale.map((r, i) => (
                                            <li key={i} className="flex items-start gap-2">
                                                {r.status === 'pass' ? (
                                                    <CheckCircle2 className="text-[var(--nadi-gain-ink)] mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                ) : r.status === 'warning' ? (
                                                    <AlertTriangle className="text-[var(--nadi-loss-ink)] mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                ) : r.status === 'fail' ? (
                                                    <XCircle className="text-[var(--nadi-loss-ink)] mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                ) : (
                                                    <Info className="text-muted-foreground mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                )}
                                                <span>{r.text}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    {activeSignal.invalidation_condition && (
                                        <p className="text-muted-foreground pt-1 text-[11px]">{activeSignal.invalidation_condition}</p>
                                    )}
                                    {(activeSignal.stance === 'no_setup' || activeSignal.stance === 'insufficient_data') && (
                                        <p className="text-muted-foreground pt-1 text-[11px]">{t('signals.empty_note')}</p>
                                    )}
                                </div>
                            </section>
                        )}

                        <PositionSizerCard
                            currency={instrument.currency}
                            lotSize={instrument.lot_size}
                            defaultEntry={activeSignal?.entry_high ?? instrument.close}
                            defaultStopLoss={activeSignal?.stop_loss}
                            defaultTarget={activeSignal?.target_1}
                            tradingCosts={tradingCosts}
                        />

                        {signalHistory.length > 0 && (
                            <section className="bg-card border-border rounded-md border p-4 space-y-2">
                                <h2 className="text-sm font-semibold">{t('signals.history')}</h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-xs">
                                        <thead>
                                            <tr className="border-border border-b text-left">
                                                <th className="py-1.5 font-semibold">Tanggal</th>
                                                <th className="py-1.5 font-semibold">Status</th>
                                                <th className="py-1.5 text-right font-semibold">Entry</th>
                                                <th className="py-1.5 text-right font-semibold">Stop-Loss</th>
                                                <th className="py-1.5 text-right font-semibold">Target 1</th>
                                                <th className="py-1.5 text-right font-semibold">Net R:R</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {signalHistory.map((h) => (
                                                <tr key={h.id}>
                                                    <td className="num py-2">{formatDate(h.signal_date, locale)}</td>
                                                    <td className="py-2">{t(`signals.stance.${h.stance}`)}</td>
                                                    <td className="num py-2 text-right">{formatPrice(h.entry_low, instrument.currency, locale)}</td>
                                                    <td className="num py-2 text-right">{formatPrice(h.stop_loss, instrument.currency, locale)}</td>
                                                    <td className="num py-2 text-right">{formatPrice(h.target_1, instrument.currency, locale)}</td>
                                                    <td className="num py-2 text-right">{h.net_rr ? `${formatNumber(h.net_rr, locale, 2, 2)}x` : '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
