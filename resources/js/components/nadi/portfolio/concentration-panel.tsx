import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, Settings2 } from 'lucide-react';
import { useState } from 'react';

export interface ConcentrationData {
    top_3_weight_pct: string;
    top_5_weight_pct: string;
    top_10_weight_pct: string;
    largest_holding: {
        symbol: string;
        name: string;
        exchange_code: string;
        market_value: string;
        weight_holdings_pct: string;
        weight_equity_pct: string;
    } | null;
    hhi_holdings: string | null;
    effective_holdings: string | null;
    hhi_level: 'diversified' | 'moderate' | 'concentrated' | 'empty';
    warnings: Array<{
        code: string;
        severity: string;
        dimension: string;
        subject: string;
        actual_pct: string;
        threshold_pct: string;
        message: string;
    }>;
}

interface ConcentrationPanelProps {
    concentration: ConcentrationData;
    thresholds: Record<string, number>;
    reportingCurrency: string;
}

export function ConcentrationPanel({ concentration, thresholds, reportingCurrency }: ConcentrationPanelProps) {
    const { t, locale } = useT();
    const [modalOpen, setModalOpen] = useState(false);

    const form = useForm({
        max_single_stock_pct: thresholds.max_single_stock_pct ?? 25,
        max_top3_pct: thresholds.max_top3_pct ?? 60,
        max_sector_pct: thresholds.max_sector_pct ?? 40,
        max_country_pct: thresholds.max_country_pct ?? 80,
        max_broker_pct: thresholds.max_broker_pct ?? 75,
        max_currency_pct: thresholds.max_currency_pct ?? 85,
        hhi_elevated_threshold: thresholds.hhi_elevated_threshold ?? 2500,
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/portfolio/concentration-thresholds', {
            preserveScroll: true,
            onSuccess: () => setModalOpen(false),
        });
    };

    const hhiColor =
        concentration.hhi_level === 'concentrated'
            ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
            : concentration.hhi_level === 'moderate'
              ? 'bg-[var(--nadi-lavender)]/25 text-[var(--nadi-lavender-ink)]'
              : 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]';

    return (
        <section className="bg-card border-border rounded-md border p-4 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold">{t('portfolio.conc.title')}</h2>
                    <p className="text-muted-foreground text-xs">{t('portfolio.conc.disclaimer')}</p>
                </div>

                <Button type="button" size="sm" variant="outline" className="h-8 gap-1.5 text-xs" onClick={() => setModalOpen(true)}>
                    <Settings2 className="h-3.5 w-3.5" />
                    <span>{t('portfolio.conc.configure')}</span>
                </Button>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 text-xs">
                <div className="border-border rounded border p-3">
                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.conc.largest')}</span>
                    {concentration.largest_holding ? (
                        <>
                            <span className="num text-base font-bold">
                                {concentration.largest_holding.symbol} ({formatNumber(concentration.largest_holding.weight_holdings_pct, locale, 2, 2)}%)
                            </span>
                            <span className="text-muted-foreground num block text-[11px]">
                                <Money value={concentration.largest_holding.market_value} currency={reportingCurrency} />
                            </span>
                        </>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </div>

                <div className="border-border rounded border p-3">
                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.conc.top3')}</span>
                    <span className="num text-base font-bold">{formatNumber(concentration.top_3_weight_pct, locale, 2, 2)}%</span>
                </div>

                <div className="border-border rounded border p-3">
                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.conc.top5')} / Top 10</span>
                    <span className="num text-base font-semibold">
                        {formatNumber(concentration.top_5_weight_pct, locale, 1, 1)}% / {formatNumber(concentration.top_10_weight_pct, locale, 1, 1)}%
                    </span>
                </div>

                <div className="border-border rounded border p-3">
                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.conc.hhi')}</span>
                    <div className="flex items-center gap-2 mt-0.5">
                        <span className="num text-base font-bold">
                            {concentration.hhi_holdings !== null ? formatNumber(concentration.hhi_holdings, locale, 0, 0) : '—'}
                        </span>
                        <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-semibold', hhiColor)}>
                            {t(`portfolio.conc.level.${concentration.hhi_level}`)}
                        </span>
                    </div>
                </div>

                <div className="border-border rounded border p-3">
                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.conc.eff_n')}</span>
                    <span className="num text-base font-bold">
                        {concentration.effective_holdings !== null ? formatNumber(concentration.effective_holdings, locale, 2, 2) : '—'}
                    </span>
                </div>
            </div>

            {concentration.warnings.length > 0 ? (
                <div className="space-y-1.5">
                    {concentration.warnings.map((w) => (
                        <div
                            key={w.code}
                            className={cn(
                                'flex items-start gap-2 rounded border px-3 py-2 text-xs',
                                w.severity === 'high'
                                    ? 'border-[var(--nadi-loss-ink)]/30 bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                                    : 'border-border bg-muted/40 text-foreground',
                            )}
                        >
                            {w.severity === 'high' ? (
                                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            ) : (
                                <Info className="text-muted-foreground mt-0.5 h-3.5 w-3.5 shrink-0" />
                            )}
                            <span>{w.message}</span>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] flex items-center gap-2 rounded px-3 py-2 text-xs font-medium">
                    <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                    <span>Tidak ada batas konsentrasi yang terlampaui berdasarkan konfigurasi Anda saat ini.</span>
                </div>
            )}

            <Dialog open={modalOpen} onOpenChange={setModalOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('portfolio.conc.configure')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={save} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            {(
                                [
                                    ['max_single_stock_pct', 'Max Single Stock (%)'],
                                    ['max_top3_pct', 'Max Top 3 (%)'],
                                    ['max_sector_pct', 'Max Sector (%)'],
                                    ['max_country_pct', 'Max Country (%)'],
                                    ['max_broker_pct', 'Max Broker Account (%)'],
                                    ['max_currency_pct', 'Max Currency (%)'],
                                    ['hhi_elevated_threshold', 'HHI Threshold (0–10000)'],
                                ] as Array<[keyof typeof form.data, string]>
                            ).map(([key, label]) => (
                                <div key={key} className="space-y-1">
                                    <Label className="text-[11px]">{label}</Label>
                                    <Input
                                        type="number"
                                        step="any"
                                        value={form.data[key]}
                                        onChange={(e) => form.setData(key, Number(e.target.value))}
                                        className="num h-8 text-xs"
                                        required
                                    />
                                </div>
                            ))}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setModalOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('app.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
