import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Calculator } from 'lucide-react';
import { useEffect, useState } from 'react';

interface PositionSizerCardProps {
    currency: string;
    lotSize: number;
    defaultEntry?: string | null;
    defaultStopLoss?: string | null;
    defaultTarget?: string | null;
    tradingCosts?: {
        buy_fee_pct: string;
        sell_fee_pct: string;
        slippage_pct: string;
    };
}

interface SizingResult {
    lots: number;
    lot_size: number;
    shares: number;
    effective_entry: string;
    effective_stop: string;
    gross_position_value: string;
    buy_fee: string;
    sell_fee_at_stop: string;
    total_capital_required: string;
    max_capital_at_risk: string;
    actual_capital_at_risk: string;
    actual_risk_pct: string;
    capped_by_capital: boolean;
    net_rr: string | null;
    expected_net_profit: string | null;
}

function readXsrfToken(): string {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function PositionSizerCard({
    currency,
    lotSize,
    defaultEntry,
    defaultStopLoss,
    defaultTarget,
    tradingCosts,
}: PositionSizerCardProps) {
    const { t, locale } = useT();
    const defaultCap = currency === 'IDR' ? '50000000' : '10000';

    const [capital, setCapital] = useState(defaultCap);
    const [maxRiskPct, setMaxRiskPct] = useState('1.5');
    const [entryPrice, setEntryPrice] = useState(defaultEntry ?? '');
    const [stopLoss, setStopLoss] = useState(defaultStopLoss ?? '');
    const [targetPrice, setTargetPrice] = useState(defaultTarget ?? '');
    const [lot, setLot] = useState(String(lotSize || 1));
    const [buyFeePct, setBuyFeePct] = useState(String(Number(tradingCosts?.buy_fee_pct ?? '0.0015') * 100));
    const [sellFeePct, setSellFeePct] = useState(String(Number(tradingCosts?.sell_fee_pct ?? '0.0025') * 100));
    const [slippagePct, setSlippagePct] = useState(String(Number(tradingCosts?.slippage_pct ?? '0.0010') * 100));

    const [result, setResult] = useState<SizingResult | null>(null);
    const [errorMsg, setErrorMsg] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (defaultEntry) setEntryPrice(defaultEntry);
        if (defaultStopLoss) setStopLoss(defaultStopLoss);
        if (defaultTarget) setTargetPrice(defaultTarget);
        setLot(String(lotSize || 1));
        setResult(null);
        setErrorMsg(null);
    }, [defaultEntry, defaultStopLoss, defaultTarget, lotSize]);

    const calculate = async (e?: React.FormEvent) => {
        e?.preventDefault();
        if (!entryPrice || !stopLoss || !capital) {
            return;
        }
        setLoading(true);
        setErrorMsg(null);
        try {
            const res = await fetch('/entry-exit/position-size', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    capital: Number(capital),
                    max_risk_pct: Number(maxRiskPct),
                    entry_price: Number(entryPrice),
                    stop_loss: Number(stopLoss),
                    target_price: targetPrice ? Number(targetPrice) : null,
                    lot_size: Number(lot) || 1,
                    buy_fee_pct: Number(buyFeePct) / 100,
                    sell_fee_pct: Number(sellFeePct) / 100,
                    slippage_pct: Number(slippagePct) / 100,
                }),
            });
            const data = await res.json();
            if (!res.ok) {
                setErrorMsg(data.message ?? t('app.error'));
                setResult(null);
            } else {
                setResult(data as SizingResult);
            }
        } catch {
            setErrorMsg(t('app.error'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <section className="bg-card border-border rounded-md border p-4 space-y-4">
            <div className="flex items-center gap-2">
                <Calculator className="text-muted-foreground h-4 w-4" />
                <h2 className="text-sm font-semibold">{t('sizing.title')}</h2>
            </div>

            <form onSubmit={calculate} className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div className="space-y-1">
                    <Label className="text-[11px]">
                        {t('sizing.capital')} ({currency})
                    </Label>
                    <Input type="number" step="any" value={capital} onChange={(e) => setCapital(e.target.value)} className="num h-8 text-xs" required />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.max_risk_pct')}</Label>
                    <Input type="number" step="0.1" value={maxRiskPct} onChange={(e) => setMaxRiskPct(e.target.value)} className="num h-8 text-xs" required />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.entry_price')}</Label>
                    <Input type="number" step="any" value={entryPrice} onChange={(e) => setEntryPrice(e.target.value)} className="num h-8 text-xs" required />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.stop_loss')}</Label>
                    <Input type="number" step="any" value={stopLoss} onChange={(e) => setStopLoss(e.target.value)} className="num h-8 text-xs" required />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.target_price')}</Label>
                    <Input type="number" step="any" value={targetPrice} onChange={(e) => setTargetPrice(e.target.value)} className="num h-8 text-xs" />
                </div>

                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.lot_size')}</Label>
                    <Input type="number" min="1" value={lot} onChange={(e) => setLot(e.target.value)} className="num h-8 text-xs" required />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.buy_fee_pct')}</Label>
                    <Input type="number" step="0.01" value={buyFeePct} onChange={(e) => setBuyFeePct(e.target.value)} className="num h-8 text-xs" />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.sell_fee_pct')}</Label>
                    <Input type="number" step="0.01" value={sellFeePct} onChange={(e) => setSellFeePct(e.target.value)} className="num h-8 text-xs" />
                </div>
                <div className="space-y-1">
                    <Label className="text-[11px]">{t('sizing.slippage_pct')}</Label>
                    <Input type="number" step="0.01" value={slippagePct} onChange={(e) => setSlippagePct(e.target.value)} className="num h-8 text-xs" />
                </div>
                <div className="flex items-end">
                    <Button type="submit" size="sm" disabled={loading || !entryPrice || !stopLoss} className="h-8 w-full text-xs">
                        {loading ? t('app.loading') : t('sizing.calculate')}
                    </Button>
                </div>
            </form>

            {errorMsg && <p className="text-[var(--nadi-loss-ink)] text-xs font-medium">{errorMsg}</p>}

            {result && (
                <div className="border-border bg-muted/30 grid gap-3 rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-5 text-xs">
                    <div>
                        <span className="text-muted-foreground block text-[11px]">{t('sizing.lots_recommended')}</span>
                        <span className="num text-base font-bold">
                            {formatNumber(result.lots, locale, 0, 0)} lot
                        </span>
                    </div>
                    <div>
                        <span className="text-muted-foreground block text-[11px]">{t('sizing.shares_total')}</span>
                        <span className="num text-base font-semibold">{formatNumber(result.shares, locale, 0, 0)}</span>
                    </div>
                    <div>
                        <span className="text-muted-foreground block text-[11px]">{t('sizing.capital_required')}</span>
                        <span className="num font-semibold">{formatPrice(result.total_capital_required, currency, locale)}</span>
                        {result.capped_by_capital && (
                            <span className="text-[var(--nadi-loss-ink)] block text-[10px]">{t('sizing.capped_note')}</span>
                        )}
                    </div>
                    <div>
                        <span className="text-muted-foreground block text-[11px]">{t('sizing.actual_risk')}</span>
                        <span className="num font-semibold">
                            {formatPrice(result.actual_capital_at_risk, currency, locale)} ({formatNumber(result.actual_risk_pct, locale, 2, 2)}%)
                        </span>
                    </div>
                    <div>
                        <span className="text-muted-foreground block text-[11px]">{t('sizing.net_rr')}</span>
                        <span className="num font-semibold">
                            {result.net_rr ? `${formatNumber(result.net_rr, locale, 2, 2)}x` : '—'}
                        </span>
                    </div>
                </div>
            )}
        </section>
    );
}
