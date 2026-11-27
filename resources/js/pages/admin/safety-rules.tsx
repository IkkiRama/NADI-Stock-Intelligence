import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface SafetyRulePageProps {
    config: {
        id: number;
        code: string;
        name: string;
        version: string;
        thresholds: Record<string, number>;
    };
    instruments: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        risk_score: string | null;
        risk_category: string;
        safeguard_triggered: boolean;
        is_suspended: boolean;
        has_exchange_warning: boolean;
        has_special_monitoring: boolean;
        free_float_pct: string;
        top_holder_pct: string;
        status_notes: string;
    }>;
}

export default function SafetyRules({ config, instruments }: SafetyRulePageProps) {
    const { t } = useT();
    const { flash } = usePage<SharedData>().props;
    const { data, setData, patch, processing } = useForm(config.thresholds);
    const [savingInstId, setSavingInstId] = useState<number | null>(null);
    const [regDrafts, setRegDrafts] = useState<Record<number, SafetyRulePageProps['instruments'][number]>>(() =>
        Object.fromEntries(instruments.map((i) => [i.id, i])),
    );

    const submitThresholds = (e: React.FormEvent) => {
        e.preventDefault();
        patch('/admin/safety-rules', { preserveScroll: true });
    };

    const saveInstrument = (id: number) => {
        const draft = regDrafts[id];
        if (!draft) return;
        setSavingInstId(id);
        router.patch(
            `/admin/safety-rules/instruments/${id}`,
            {
                is_suspended: draft.is_suspended,
                has_exchange_warning: draft.has_exchange_warning,
                has_special_monitoring: draft.has_special_monitoring,
                free_float_pct: draft.free_float_pct === '' ? null : Number(draft.free_float_pct),
                top_holder_pct: draft.top_holder_pct === '' ? null : Number(draft.top_holder_pct),
                status_notes: draft.status_notes || null,
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingInstId(null),
            },
        );
    };

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.safety_rules'), href: '/admin/safety-rules' }]}>
            <Head title={t('admin.safety.title')} />

            <div className="space-y-5">
                {flash?.status && (
                    <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded-md px-3 py-2 text-xs font-medium">
                        {t('admin.safety.saved')}
                    </div>
                )}

                <form onSubmit={submitThresholds} className="bg-card border-border space-y-4 rounded-md border p-4">
                    <div>
                        <h2 className="text-sm font-semibold">{t('admin.safety.thresholds')}</h2>
                        <p className="text-muted-foreground text-xs">{t('admin.safety.subtitle')}</p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-5">
                        {Object.entries(data).map(([key, val]) => (
                            <div key={key} className="space-y-1">
                                <Label className="font-mono text-[11px]">{key}</Label>
                                <Input
                                    type="number"
                                    step="any"
                                    value={val}
                                    onChange={(e) => setData(key, Number(e.target.value))}
                                    className="num h-8 text-xs"
                                />
                            </div>
                        ))}
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={processing}>
                            {t('app.save')}
                        </Button>
                    </div>
                </form>

                <section className="bg-card border-border space-y-3 rounded-md border p-4">
                    <h2 className="text-sm font-semibold">{t('admin.safety.regulatory')}</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr className="border-border border-b text-left">
                                    <th className="py-2 font-semibold">Emiten</th>
                                    <th className="py-2 font-semibold">Safety</th>
                                    <th className="py-2 font-semibold">Suspended</th>
                                    <th className="py-2 font-semibold">Warning</th>
                                    <th className="py-2 font-semibold">Special Mon.</th>
                                    <th className="py-2 font-semibold">Free Float %</th>
                                    <th className="py-2 font-semibold">Notes</th>
                                    <th className="py-2 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {instruments.map((inst) => {
                                    const draft = regDrafts[inst.id] ?? inst;
                                    return (
                                        <tr key={inst.id}>
                                            <td className="py-2 font-medium">
                                                {inst.symbol}{' '}
                                                <span className="text-muted-foreground text-[10px]">{inst.exchange_code}</span>
                                            </td>
                                            <td className="py-2">
                                                <SafetyBadge category={inst.risk_category} score={inst.risk_score} safeguard={inst.safeguard_triggered} />
                                            </td>
                                            <td className="py-2">
                                                <Checkbox
                                                    checked={draft.is_suspended}
                                                    onCheckedChange={(c) =>
                                                        setRegDrafts({ ...regDrafts, [inst.id]: { ...draft, is_suspended: Boolean(c) } })
                                                    }
                                                />
                                            </td>
                                            <td className="py-2">
                                                <Checkbox
                                                    checked={draft.has_exchange_warning}
                                                    onCheckedChange={(c) =>
                                                        setRegDrafts({ ...regDrafts, [inst.id]: { ...draft, has_exchange_warning: Boolean(c) } })
                                                    }
                                                />
                                            </td>
                                            <td className="py-2">
                                                <Checkbox
                                                    checked={draft.has_special_monitoring}
                                                    onCheckedChange={(c) =>
                                                        setRegDrafts({ ...regDrafts, [inst.id]: { ...draft, has_special_monitoring: Boolean(c) } })
                                                    }
                                                />
                                            </td>
                                            <td className="py-2">
                                                <Input
                                                    type="number"
                                                    step="0.1"
                                                    value={draft.free_float_pct}
                                                    onChange={(e) =>
                                                        setRegDrafts({ ...regDrafts, [inst.id]: { ...draft, free_float_pct: e.target.value } })
                                                    }
                                                    className="num h-7 w-20 text-xs"
                                                />
                                            </td>
                                            <td className="py-2">
                                                <Input
                                                    value={draft.status_notes}
                                                    onChange={(e) =>
                                                        setRegDrafts({ ...regDrafts, [inst.id]: { ...draft, status_notes: e.target.value } })
                                                    }
                                                    className="h-7 w-44 text-xs"
                                                />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 px-2.5 text-xs"
                                                    disabled={savingInstId === inst.id}
                                                    onClick={() => saveInstrument(inst.id)}
                                                >
                                                    {t('app.save')}
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}
