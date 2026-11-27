import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';

interface ScoringConfigProps {
    config: {
        code: string;
        name: string;
        version: string;
        weights: {
            fundamentals: number;
            valuation: number;
            technical: number;
            risk: number;
            catalysts: number;
        };
    };
}

export default function AdminScoring({ config }: ScoringConfigProps) {
    const { t } = useT();

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: config.name,
        weights: { ...config.weights },
    });

    const total =
        Number(data.weights.fundamentals || 0) +
        Number(data.weights.valuation || 0) +
        Number(data.weights.technical || 0) +
        Number(data.weights.risk || 0) +
        Number(data.weights.catalysts || 0);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        patch(route('admin.scoring.update'), { preserveScroll: true });
    };

    const fields: Array<{ key: keyof typeof data.weights; label: string }> = [
        { key: 'fundamentals', label: t('stocks.score.comp.fundamentals') },
        { key: 'valuation', label: t('stocks.score.comp.valuation') },
        { key: 'technical', label: t('stocks.score.comp.technical') },
        { key: 'risk', label: t('stocks.score.comp.risk') },
        { key: 'catalysts', label: t('stocks.score.comp.catalysts') },
    ];

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.scoring'), href: '/admin/scoring' }]}>
            <Head title={t('admin.scoring.title')} />

            <form onSubmit={submit} className="bg-card border-border max-w-xl space-y-4 rounded-md border p-5">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-sm font-semibold">{t('admin.scoring.title')}</h2>
                        <p className="text-muted-foreground text-xs">{t('admin.scoring.total_hint')}</p>
                    </div>
                    <span className="bg-muted rounded px-2 py-0.5 font-mono text-xs">{config.version}</span>
                </div>

                <div className="space-y-1.5">
                    <Label>Nama Konfigurasi</Label>
                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    {fields.map((f) => (
                        <div key={f.key} className="space-y-1.5">
                            <Label>{f.label} (%)</Label>
                            <Input
                                type="number"
                                min={0}
                                max={100}
                                value={data.weights[f.key]}
                                onChange={(e) =>
                                    setData('weights', {
                                        ...data.weights,
                                        [f.key]: Number(e.target.value),
                                    })
                                }
                            />
                        </div>
                    ))}
                </div>

                <InputError message={(errors as Record<string, string>).weights} />

                <div className="flex items-center justify-between border-t pt-3">
                    <div className="text-xs">
                        Total:{' '}
                        <strong className={cn('num', total === 100 ? 'text-[var(--nadi-gain-ink)]' : 'text-[var(--nadi-loss-ink)]')}>
                            {total}%
                        </strong>
                    </div>

                    <div className="flex items-center gap-3">
                        {recentlySuccessful && <span className="text-xs font-medium text-[var(--nadi-gain-ink)]">Tersimpan</span>}
                        <Button type="submit" size="sm" disabled={processing || total !== 100}>
                            {t('app.save')}
                        </Button>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
