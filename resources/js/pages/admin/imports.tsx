import InputError from '@/components/input-error';
import { MetricCard } from '@/components/nadi/metric-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/react';

interface ImportRunRow {
    id: number;
    dataset: string;
    mode: string;
    status: string;
    rows_processed: number;
    rows_inserted: number;
    rows_skipped: number;
    error_summary: string | null;
    started_at: string | null;
    finished_at: string | null;
    source: string;
    user_email: string;
}

interface AdminImportsProps {
    runs: ImportRunRow[];
    coverage: Record<string, number>;
    datasets: string[];
}

export default function AdminImports({ runs, coverage, datasets }: AdminImportsProps) {
    const { t, locale } = useT();
    const { data, setData, post, processing, errors, reset } = useForm<{ dataset: string; file: File | null }>({
        dataset: datasets[0] || 'prices_daily',
        file: null,
    });

    const submitCsv = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.imports.csv'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset('file'),
        });
    };

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.imports'), href: '/admin/imports' }]}>
            <Head title={t('admin.imports.title')} />

            <div className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard label="Daily Price Bars" value={formatNumber(coverage.prices_daily, locale, 0, 0)} />
                    <MetricCard label="Market Holidays" value={formatNumber(coverage.market_holidays, locale, 0, 0)} />
                    <MetricCard label="Financial Statements" value={formatNumber(coverage.financial_statements, locale, 0, 0)} />
                    <MetricCard label="Scores Computed" value={formatNumber(coverage.scores_latest, locale, 0, 0)} />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <form onSubmit={submitCsv} className="bg-card border-border space-y-4 rounded-md border p-4 lg:col-span-2">
                        <h2 className="text-sm font-semibold">{t('admin.imports.upload_csv')}</h2>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>{t('admin.imports.dataset')}</Label>
                                <Select value={data.dataset} onValueChange={(v) => setData('dataset', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {datasets.map((ds) => (
                                            <SelectItem key={ds} value={ds}>
                                                {ds}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.dataset} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>CSV (.csv / .txt, max 5MB)</Label>
                                <Input type="file" accept=".csv,.txt" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                                <InputError message={errors.file} />
                            </div>
                        </div>

                        <p className="text-muted-foreground font-mono text-[11px]">
                            prices_daily: exchange_code,symbol,trade_date,open,high,low,close,volume | market_holidays: exchange_code,date,name
                        </p>

                        <Button type="submit" size="sm" disabled={processing || !data.file}>
                            {t('admin.imports.upload_csv')}
                        </Button>
                    </form>

                    <div className="bg-card border-border flex flex-col justify-between rounded-md border p-4">
                        <div className="space-y-2">
                            <h2 className="text-sm font-semibold">{t('admin.imports.run_analytics')}</h2>
                            <p className="text-muted-foreground text-xs leading-relaxed">
                                Hitung ulang kuotasi terbaru, rasio fundamental, indikator teknikal, dan skor kuantitatif untuk seluruh instrumen aktif.
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => router.post(route('admin.imports.refresh'), {}, { preserveScroll: true })}
                            className="mt-4"
                        >
                            {t('admin.imports.run_analytics')}
                        </Button>
                    </div>
                </div>

                <div className="bg-card border-border overflow-x-auto rounded-md border">
                    <div className="border-border border-b px-4 py-2.5 text-sm font-semibold">{t('admin.imports.history')}</div>
                    <table className="w-full border-collapse text-xs">
                        <thead>
                            <tr className="border-border bg-muted/50 border-b text-left">
                                <th className="px-3 py-2 font-semibold">Waktu</th>
                                <th className="px-3 py-2 font-semibold">Dataset</th>
                                <th className="px-3 py-2 font-semibold">Mode</th>
                                <th className="px-3 py-2 font-semibold">Status</th>
                                <th className="px-3 py-2 text-right font-semibold">Diproses</th>
                                <th className="px-3 py-2 text-right font-semibold">Tersimpan</th>
                                <th className="px-3 py-2 text-right font-semibold">Dilewati</th>
                                <th className="px-3 py-2 font-semibold">Catatan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {runs.map((r) => (
                                <tr key={r.id}>
                                    <td className="num text-muted-foreground px-3 py-2">{formatDateTime(r.started_at, locale, 'Asia/Jakarta')}</td>
                                    <td className="px-3 py-2 font-mono">{r.dataset}</td>
                                    <td className="px-3 py-2">{r.mode}</td>
                                    <td className="px-3 py-2 font-medium">{r.status}</td>
                                    <td className="num px-3 py-2 text-right">{r.rows_processed}</td>
                                    <td className="num px-3 py-2 text-right">{r.rows_inserted}</td>
                                    <td className="num px-3 py-2 text-right">{r.rows_skipped}</td>
                                    <td className="text-muted-foreground max-w-xs truncate px-3 py-2">{r.error_summary ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
