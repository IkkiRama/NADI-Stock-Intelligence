import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataTable, type DataTableColumn } from '@/components/nadi/data-table';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Info, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

interface SafetyRuleItem {
    code: string;
    severity: string;
    points: number;
    title: string;
    evidence: string;
}

interface SafetyRow {
    id: number;
    symbol: string;
    name: string;
    sector: string | null;
    currency: string;
    exchange_code: string;
    close: string | null;
    change_pct: string | null;
    as_of_date: string | null;
    risk_score: string | null;
    risk_category: string;
    safeguard_triggered: boolean;
    triggered_rules: SafetyRuleItem[];
    data_confidence: string | null;
    missing_factors: string[];
    is_suspended: boolean;
    has_special_monitoring: boolean;
    has_exchange_warning: boolean;
    is_demo: boolean;
}

interface StockSafetyProps {
    assessments: {
        data: SafetyRow[];
        total: number;
    };
    summary: {
        safeguard: number;
        high: number;
        elevated: number;
        moderate: number;
        low: number;
    };
    exchanges: Array<{ code: string; name: string }>;
    filters: {
        q: string;
        exchange: string;
        category: string;
        safeguard_only: boolean;
    };
}

export default function StockSafety({ assessments, summary, exchanges, filters }: StockSafetyProps) {
    const { t, locale } = useT();
    const [form, setForm] = useState(filters);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.stock_safety'), href: '/stock-safety' }];

    const apply = (overrides: Partial<typeof form> = {}) => {
        const next = { ...form, ...overrides };
        setForm(next);
        router.get(
            '/stock-safety',
            {
                q: next.q,
                exchange: next.exchange,
                category: next.category,
                safeguard_only: next.safeguard_only ? '1' : '',
            },
            { preserveState: true, replace: true },
        );
    };

    const columns: DataTableColumn<SafetyRow>[] = [
        {
            key: 'symbol',
            header: t('stocks.col.symbol'),
            sticky: true,
            cell: (r) => (
                <Link href={`/stocks/${r.exchange_code}/${r.symbol}`} className="flex items-center gap-1.5 font-semibold hover:underline">
                    <span>{r.symbol}</span>
                    <span className="text-muted-foreground text-[10px] font-normal">{r.exchange_code}</span>
                    {r.is_demo && <DemoBadge />}
                </Link>
            ),
        },
        {
            key: 'risk_category',
            header: t('safety.risk_score'),
            cell: (r) => <SafetyBadge category={r.risk_category} score={r.risk_score} safeguard={r.safeguard_triggered} />,
        },
        {
            key: 'close',
            header: t('stocks.col.price'),
            align: 'right',
            cell: (r) => (
                <div className="text-right">
                    <Money value={r.close} currency={r.currency} />
                    <div>
                        <ChangeBadge changePct={r.change_pct} />
                    </div>
                </div>
            ),
        },
        {
            key: 'triggered_rules',
            header: t('safety.triggered_rules'),
            cell: (r) =>
                r.triggered_rules.length === 0 ? (
                    <span className="text-muted-foreground text-xs">{t('safety.no_rules')}</span>
                ) : (
                    <ul className="space-y-0.5 text-xs">
                        {r.triggered_rules.slice(0, 3).map((rule) => (
                            <li key={rule.code} title={rule.evidence}>
                                <span className="font-medium">{rule.title}</span>{' '}
                                <span className="text-muted-foreground text-[11px]">({rule.evidence})</span>
                            </li>
                        ))}
                    </ul>
                ),
        },
        {
            key: 'confidence',
            header: t('safety.confidence'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{r.data_confidence ? `${formatNumber(r.data_confidence, locale, 0, 0)}%` : '—'}</span>,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('safety.title')} />

            <div className="space-y-4">
                <div className="flex items-start gap-2.5">
                    <div className="bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                        <ShieldAlert className="h-4 w-4" />
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">{t('safety.title')}</h1>
                        <p className="text-muted-foreground text-xs">{t('safety.subtitle')}</p>
                    </div>
                </div>

                <div className="border-border bg-muted/40 text-muted-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-xs">
                    <Info className="h-3.5 w-3.5 shrink-0" />
                    <span>{t('safety.disclaimer')}</span>
                </div>

                <div className="grid gap-3 sm:grid-cols-5">
                    {[
                        { label: t('safety.summary.safeguard'), val: summary.safeguard, tone: 'text-[var(--nadi-loss-ink)]' },
                        { label: t('safety.category.high'), val: summary.high, tone: 'text-[var(--nadi-loss-ink)]' },
                        { label: t('safety.category.elevated'), val: summary.elevated, tone: 'text-[var(--nadi-lavender-ink)]' },
                        { label: t('safety.category.moderate'), val: summary.moderate, tone: 'text-[var(--nadi-ice-ink)]' },
                        { label: t('safety.category.low'), val: summary.low, tone: 'text-[var(--nadi-gain-ink)]' },
                    ].map((card) => (
                        <div key={card.label} className="bg-card border-border rounded-md border p-3">
                            <span className="text-muted-foreground block text-[11px]">{card.label}</span>
                            <span className={`num text-xl font-bold ${card.tone}`}>{card.val}</span>
                        </div>
                    ))}
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply();
                    }}
                    className="bg-card border-border grid gap-3 rounded-md border p-4 sm:grid-cols-2 md:grid-cols-5"
                >
                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('app.search')}</Label>
                        <Input
                            value={form.q}
                            onChange={(e) => setForm({ ...form, q: e.target.value })}
                            placeholder={t('stocks.search_placeholder')}
                            className="h-8 text-xs"
                        />
                    </div>

                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('stocks.col.exchange')}</Label>
                        <Select value={form.exchange} onValueChange={(v) => apply({ exchange: v })}>
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                {exchanges.map((ex) => (
                                    <SelectItem key={ex.code} value={ex.code}>
                                        {ex.code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('safety.risk_score')}</Label>
                        <Select value={form.category} onValueChange={(v) => apply({ category: v })}>
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                <SelectItem value="high">{t('safety.category.high')}</SelectItem>
                                <SelectItem value="elevated">{t('safety.category.elevated')}</SelectItem>
                                <SelectItem value="moderate">{t('safety.category.moderate')}</SelectItem>
                                <SelectItem value="low">{t('safety.category.low')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-end pb-1.5">
                        <label className="flex items-center gap-1.5 text-xs">
                            <Checkbox
                                checked={form.safeguard_only}
                                onCheckedChange={(c) => apply({ safeguard_only: Boolean(c) })}
                            />
                            <span>{t('safety.safeguard_only')}</span>
                        </label>
                    </div>

                    <div className="flex items-end">
                        <Button type="submit" size="sm" className="h-8 w-full text-xs">
                            {t('app.filter')} ({assessments.total})
                        </Button>
                    </div>
                </form>

                <DataTable
                    rows={assessments.data}
                    columns={columns}
                    rowKey={(r) => r.id}
                    onRowClick={(r) => router.visit(`/stocks/${r.exchange_code}/${r.symbol}`)}
                    emptyState={<EmptyState title={t('stocks.empty_title')} />}
                />
            </div>
        </AppLayout>
    );
}
