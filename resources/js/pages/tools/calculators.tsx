import { PositionSizerCard } from '@/components/nadi/position-sizer-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Calculator, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

interface CalculatorsProps {
    exchanges: Array<{ code: string; name: string; currency: string; default_lot_size: number }>;
    tradingCosts: Record<string, { buy_fee_pct: string; sell_fee_pct: string; slippage_pct: string }>;
    currencies: string[];
    fxRatesToIdr: Record<string, string | null>;
}

export default function Calculators({ exchanges, tradingCosts, currencies, fxRatesToIdr }: CalculatorsProps) {
    const { t, locale } = useT();
    const [tab, setTab] = useState<'trade' | 'average' | 'sizing' | 'dividend' | 'compound'>('trade');

    // 1. Trade & Break-even calculator state
    const [exchangeCode, setExchangeCode] = useState('IDX');
    const [lots, setLots] = useState('10');
    const [buyPrice, setBuyPrice] = useState('9500');
    const [sellPrice, setSellPrice] = useState('10200');

    const selectedEx = exchanges.find((e) => e.code === exchangeCode) ?? exchanges[0];
    const exCosts = tradingCosts[exchangeCode] ?? tradingCosts.DEFAULT;
    const lotSize = selectedEx?.default_lot_size ?? 100;
    const ccy = selectedEx?.currency ?? 'IDR';

    const tradeCalc = useMemo(() => {
        const shares = (Number(lots) || 0) * lotSize;
        const bp = Number(buyPrice) || 0;
        const sp = Number(sellPrice) || 0;
        const buyRate = Number(exCosts?.buy_fee_pct ?? 0.0015);
        const sellRate = Number(exCosts?.sell_fee_pct ?? 0.0025);

        const grossBuy = shares * bp;
        const buyFee = grossBuy * buyRate;
        const totalCost = grossBuy + buyFee;

        const grossSell = shares * sp;
        const sellFee = grossSell * sellRate;
        const netProceeds = grossSell - sellFee;

        const realizedNet = netProceeds - totalCost;
        const returnPct = totalCost > 0 ? realizedNet / totalCost : 0;
        const breakEvenPrice = shares > 0 && 1 - sellRate > 0 ? totalCost / (shares * (1 - sellRate)) : 0;

        return { shares, grossBuy, buyFee, totalCost, grossSell, sellFee, netProceeds, realizedNet, returnPct, breakEvenPrice };
    }, [lots, buyPrice, sellPrice, lotSize, exCosts]);

    // 2. Average price tranches
    const [tranches, setTranches] = useState([
        { shares: '1000', price: '9000' },
        { shares: '1500', price: '9600' },
    ]);
    const avgCalc = useMemo(() => {
        let totalShares = 0;
        let totalValue = 0;
        for (const tr of tranches) {
            const s = Math.max(0, Number(tr.shares) || 0);
            const p = Math.max(0, Number(tr.price) || 0);
            totalShares += s;
            totalValue += s * p;
        }
        return { totalShares, totalValue, avgPrice: totalShares > 0 ? totalValue / totalShares : 0 };
    }, [tranches]);

    // 3. Dividend & Yield on Cost
    const [divCostPerShare, setDivCostPerShare] = useState('8500');
    const [divCurrentPrice, setDivCurrentPrice] = useState('10000');
    const [divDpsAnnual, setDivDpsAnnual] = useState('320');
    const [divTaxPct, setDivTaxPct] = useState('10');
    const [divShares, setDivShares] = useState('5000');
    const divCalc = useMemo(() => {
        const c = Number(divCostPerShare) || 0;
        const p = Number(divCurrentPrice) || 0;
        const dps = Number(divDpsAnnual) || 0;
        const tax = Math.max(0, Math.min(100, Number(divTaxPct) || 0)) / 100;
        const sh = Number(divShares) || 0;
        const netDps = dps * (1 - tax);
        return {
            grossYield: p > 0 ? dps / p : 0,
            netYieldOnCost: c > 0 ? netDps / c : 0,
            annualNetIncome: netDps * sh,
            monthlyAvgIncome: (netDps * sh) / 12,
        };
    }, [divCostPerShare, divCurrentPrice, divDpsAnnual, divTaxPct, divShares]);

    // 4. Compound / CAGR + FX Converter
    const [cagrStart, setCagrStart] = useState('50000000');
    const [cagrAnnualPct, setCagrAnnualPct] = useState('12');
    const [cagrMonthlyAdd, setCagrMonthlyAdd] = useState('2000000');
    const [cagrYears, setCagrYears] = useState('10');
    const compoundResult = useMemo(() => {
        let balance = Number(cagrStart) || 0;
        let principal = balance;
        const monthlyRate = (Number(cagrAnnualPct) || 0) / 100 / 12;
        const add = Number(cagrMonthlyAdd) || 0;
        const months = Math.max(1, Math.min(600, (Number(cagrYears) || 1) * 12));
        for (let m = 0; m < months; m++) {
            balance = balance * (1 + monthlyRate) + add;
            principal += add;
        }
        return { balance, principal, gain: balance - principal };
    }, [cagrStart, cagrAnnualPct, cagrMonthlyAdd, cagrYears]);

    const [fxAmount, setFxAmount] = useState('1000');
    const [fxFrom, setFxFrom] = useState('USD');
    const [fxTo, setFxTo] = useState('IDR');
    const fxConverted = useMemo(() => {
        const amt = Number(fxAmount) || 0;
        const rFrom = Number(fxRatesToIdr[fxFrom] ?? 0);
        const rTo = Number(fxRatesToIdr[fxTo] ?? 0);
        if (rFrom <= 0 || rTo <= 0) return null;
        return (amt * rFrom) / rTo;
    }, [fxAmount, fxFrom, fxTo, fxRatesToIdr]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.calculators'), href: '/calculators' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('calc.title')} />

            <div className="space-y-5">
                <div className="flex items-start gap-2.5">
                    <div className="bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                        <Calculator className="h-4 w-4" />
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">{t('calc.title')}</h1>
                        <p className="text-muted-foreground text-xs">{t('calc.subtitle')}</p>
                    </div>
                </div>

                <div className="border-border flex flex-wrap gap-1 border-b pb-2">
                    {(['trade', 'average', 'sizing', 'dividend', 'compound'] as const).map((k) => (
                        <Button key={k} type="button" size="sm" variant={tab === k ? 'default' : 'ghost'} className="h-8 text-xs" onClick={() => setTab(k)}>
                            {t(`calc.tab.${k}`)}
                        </Button>
                    ))}
                </div>

                {tab === 'trade' && (
                    <section className="bg-card border-border space-y-4 rounded-md border p-4">
                        <div className="grid gap-3 sm:grid-cols-4">
                            <div className="space-y-1">
                                <Label className="text-[11px]">{t('stocks.col.exchange')}</Label>
                                <Select value={exchangeCode} onValueChange={setExchangeCode}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {exchanges.map((ex) => (
                                            <SelectItem key={ex.code} value={ex.code}>
                                                {ex.code} (1 lot = {ex.default_lot_size})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Jumlah Lot</Label>
                                <Input type="number" value={lots} onChange={(e) => setLots(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Harga Beli ({ccy})</Label>
                                <Input type="number" step="any" value={buyPrice} onChange={(e) => setBuyPrice(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Harga Jual Target ({ccy})</Label>
                                <Input type="number" step="any" value={sellPrice} onChange={(e) => setSellPrice(e.target.value)} className="num h-8 text-xs" />
                            </div>
                        </div>

                        <div className="border-border bg-muted/30 grid gap-3 rounded-md border p-3 sm:grid-cols-5 text-xs">
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Total Saham</span>
                                <span className="num font-semibold">{formatNumber(tradeCalc.shares, locale, 0, 0)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Modal Beli (+Fee)</span>
                                <span className="num font-semibold">{formatPrice(tradeCalc.totalCost, ccy, locale)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Titik Impas (BEP)</span>
                                <span className="num font-semibold">{formatPrice(tradeCalc.breakEvenPrice, ccy, locale)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Laba/Rugi Bersih</span>
                                <span className="num font-bold">{formatPrice(tradeCalc.realizedNet, ccy, locale)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Return Bersih</span>
                                <span className="num font-semibold">{formatPct(tradeCalc.returnPct, locale)}</span>
                            </div>
                        </div>
                    </section>
                )}

                {tab === 'average' && (
                    <section className="bg-card border-border space-y-4 rounded-md border p-4">
                        <div className="space-y-2">
                            {tranches.map((tr, idx) => (
                                <div key={idx} className="grid grid-cols-3 gap-2 items-end">
                                    <div className="space-y-1">
                                        <Label className="text-[11px]">Jumlah Saham #{idx + 1}</Label>
                                        <Input
                                            type="number"
                                            value={tr.shares}
                                            onChange={(e) => {
                                                const next = [...tranches];
                                                next[idx] = { ...tr, shares: e.target.value };
                                                setTranches(next);
                                            }}
                                            className="num h-8 text-xs"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-[11px]">Harga Beli #{idx + 1}</Label>
                                        <Input
                                            type="number"
                                            step="any"
                                            value={tr.price}
                                            onChange={(e) => {
                                                const next = [...tranches];
                                                next[idx] = { ...tr, price: e.target.value };
                                                setTranches(next);
                                            }}
                                            className="num h-8 text-xs"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={tranches.length <= 1}
                                        onClick={() => setTranches(tranches.filter((_, i) => i !== idx))}
                                        className="h-8 w-10"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <Button type="button" size="sm" variant="outline" className="gap-1 text-xs" onClick={() => setTranches([...tranches, { shares: '1000', price: '9500' }])}>
                            <Plus className="h-3.5 w-3.5" />
                            <span>Tambah Tahap</span>
                        </Button>
                        <div className="border-border bg-muted/30 grid gap-3 rounded-md border p-3 sm:grid-cols-3 text-xs">
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Total Saham</span>
                                <span className="num font-semibold">{formatNumber(avgCalc.totalShares, locale, 0, 2)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Total Nilai Beli</span>
                                <span className="num font-semibold">{formatNumber(avgCalc.totalValue, locale, 0, 2)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Harga Rata-Rata</span>
                                <span className="num text-base font-bold">{formatNumber(avgCalc.avgPrice, locale, 2, 2)}</span>
                            </div>
                        </div>
                    </section>
                )}

                {tab === 'sizing' && (
                    <PositionSizerCard
                        currency={ccy}
                        lotSize={lotSize}
                        defaultEntry="9500"
                        defaultStopLoss="9000"
                        defaultTarget="10800"
                        tradingCosts={exCosts}
                    />
                )}

                {tab === 'dividend' && (
                    <section className="bg-card border-border space-y-4 rounded-md border p-4">
                        <div className="grid gap-3 sm:grid-cols-5">
                            <div className="space-y-1">
                                <Label className="text-[11px]">Avg Cost / Saham</Label>
                                <Input type="number" step="any" value={divCostPerShare} onChange={(e) => setDivCostPerShare(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Harga Pasar</Label>
                                <Input type="number" step="any" value={divCurrentPrice} onChange={(e) => setDivCurrentPrice(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Dividen Tahunan / Saham</Label>
                                <Input type="number" step="any" value={divDpsAnnual} onChange={(e) => setDivDpsAnnual(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Pajak Dividen (%)</Label>
                                <Input type="number" step="any" value={divTaxPct} onChange={(e) => setDivTaxPct(e.target.value)} className="num h-8 text-xs" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Jumlah Saham</Label>
                                <Input type="number" step="any" value={divShares} onChange={(e) => setDivShares(e.target.value)} className="num h-8 text-xs" />
                            </div>
                        </div>
                        <div className="border-border bg-muted/30 grid gap-3 rounded-md border p-3 sm:grid-cols-4 text-xs">
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Dividend Yield (Kotor)</span>
                                <span className="num font-semibold">{formatPct(divCalc.grossYield, locale, false)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Net Yield on Cost</span>
                                <span className="num font-bold">{formatPct(divCalc.netYieldOnCost, locale, false)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Pendapatan Tahunan Bersih</span>
                                <span className="num font-semibold">{formatNumber(divCalc.annualNetIncome, locale, 0, 2)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground block text-[11px]">Rata-Rata Bulanan</span>
                                <span className="num font-semibold">{formatNumber(divCalc.monthlyAvgIncome, locale, 0, 2)}</span>
                            </div>
                        </div>
                    </section>
                )}

                {tab === 'compound' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <section className="bg-card border-border space-y-3 rounded-md border p-4">
                            <h2 className="text-sm font-semibold">Simulasi Compound Growth</h2>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Modal Awal</Label>
                                    <Input type="number" value={cagrStart} onChange={(e) => setCagrStart(e.target.value)} className="num h-8 text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Setoran Bulanan</Label>
                                    <Input type="number" value={cagrMonthlyAdd} onChange={(e) => setCagrMonthlyAdd(e.target.value)} className="num h-8 text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Target CAGR (% / Tahun)</Label>
                                    <Input type="number" step="0.5" value={cagrAnnualPct} onChange={(e) => setCagrAnnualPct(e.target.value)} className="num h-8 text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Durasi (Tahun)</Label>
                                    <Input type="number" value={cagrYears} onChange={(e) => setCagrYears(e.target.value)} className="num h-8 text-xs" />
                                </div>
                            </div>
                            <div className="border-border bg-muted/30 grid grid-cols-3 gap-2 rounded-md border p-3 text-xs">
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">Total Pokok</span>
                                    <span className="num font-semibold">{formatNumber(compoundResult.principal, locale, 0, 0)}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">Akumulasi Bunga</span>
                                    <span className="num font-semibold">{formatNumber(compoundResult.gain, locale, 0, 0)}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">Nilai Akhir</span>
                                    <span className="num font-bold">{formatNumber(compoundResult.balance, locale, 0, 0)}</span>
                                </div>
                            </div>
                        </section>

                        <section className="bg-card border-border space-y-3 rounded-md border p-4">
                            <h2 className="text-sm font-semibold">Konversi Kurs Portofolio (FX Referensi)</h2>
                            <div className="grid grid-cols-3 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Nominal</Label>
                                    <Input type="number" step="any" value={fxAmount} onChange={(e) => setFxAmount(e.target.value)} className="num h-8 text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Dari</Label>
                                    <Select value={fxFrom} onValueChange={setFxFrom}>
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {c}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Ke</Label>
                                    <Select value={fxTo} onValueChange={setFxTo}>
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {c}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="border-border bg-muted/30 rounded-md border p-3 text-xs">
                                <span className="text-muted-foreground block text-[11px]">Hasil Konversi</span>
                                <span className="num text-base font-bold">
                                    {fxConverted !== null ? formatPrice(fxConverted, fxTo, locale) : t('app.unavailable')}
                                </span>
                            </div>
                        </section>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
