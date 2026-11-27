import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Bell, Calendar, Check, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface AlertsCalendarProps {
    alerts: Array<{
        id: number;
        instrument_id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        latest_close: string | null;
        alert_type: string;
        threshold_value: string | null;
        horizon: string | null;
        notes: string | null;
        is_active: boolean;
        last_triggered_at: string | null;
    }>;
    events: Array<{
        id: number;
        symbol: string;
        exchange_code: string;
        triggered_date: string;
        message: string;
        read_at: string | null;
    }>;
    unreadCount: number;
    corporateActions: Array<{
        id: number;
        symbol: string;
        exchange_code: string;
        action_type: string;
        ex_date: string;
        payment_date: string | null;
        declared_currency: string | null;
        cash_amount: string | null;
        notes: string | null;
        is_demo: boolean;
    }>;
    holidays: Array<{
        id: number;
        exchange_code: string;
        holiday_date: string;
        name: string;
    }>;
    instruments: Array<{ id: number; symbol: string; name: string; currency: string; exchange_code: string }>;
    exchanges: Array<{ code: string; name: string }>;
    selectedExchange: string;
}

export default function AlertsCalendar({
    alerts,
    events,
    unreadCount,
    corporateActions,
    holidays,
    instruments,
    exchanges,
    selectedExchange,
}: AlertsCalendarProps) {
    const { t, locale } = useT();
    const [instrumentId, setInstrumentId] = useState(String(instruments[0]?.id ?? ''));
    const [alertType, setAlertType] = useState('price_above');
    const [thresholdValue, setThresholdValue] = useState('');
    const [horizon, setHorizon] = useState('swing');
    const [notes, setNotes] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.alerts_calendar'), href: '/alerts-calendar' }];

    const createAlert = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            '/alerts',
            {
                instrument_id: Number(instrumentId),
                alert_type: alertType,
                threshold_value: alertType === 'signal_actionable' ? null : thresholdValue,
                horizon: alertType === 'signal_actionable' ? horizon : null,
                notes: notes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setThresholdValue('');
                    setNotes('');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('alerts.title')} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-lavender)]/25 text-[var(--nadi-lavender-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <Bell className="h-4 w-4" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">
                                {t('alerts.title')}{' '}
                                {unreadCount > 0 && (
                                    <span className="bg-[var(--nadi-loss-ink)] text-white ml-1 rounded-full px-2 py-0.5 text-[10px]">
                                        {unreadCount}
                                    </span>
                                )}
                            </h1>
                            <p className="text-muted-foreground text-xs">{t('alerts.subtitle')}</p>
                        </div>
                    </div>

                    <Select value={selectedExchange} onValueChange={(v) => router.get('/alerts-calendar', { exchange: v }, { preserveState: true })}>
                        <SelectTrigger className="h-8 w-40 text-xs">
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

                {/* Create Alert Form */}
                <form onSubmit={createAlert} className="bg-card border-border space-y-3 rounded-md border p-4">
                    <h2 className="text-sm font-semibold">{t('alerts.create')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('stocks.col.symbol')}</Label>
                            <Select value={instrumentId} onValueChange={setInstrumentId}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {instruments.map((inst) => (
                                        <SelectItem key={inst.id} value={String(inst.id)}>
                                            {inst.symbol} ({inst.exchange_code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('alerts.type')}</Label>
                            <Select value={alertType} onValueChange={setAlertType}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="price_above">{t('alerts.type.price_above')}</SelectItem>
                                    <SelectItem value="price_below">{t('alerts.type.price_below')}</SelectItem>
                                    <SelectItem value="safety_risk_above">{t('alerts.type.safety_risk_above')}</SelectItem>
                                    <SelectItem value="signal_actionable">{t('alerts.type.signal_actionable')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {alertType === 'signal_actionable' ? (
                            <div className="space-y-1">
                                <Label className="text-[11px]">Horizon</Label>
                                <Select value={horizon} onValueChange={setHorizon}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="short_term">{t('signals.horizon.short_term')}</SelectItem>
                                        <SelectItem value="swing">{t('signals.horizon.swing')}</SelectItem>
                                        <SelectItem value="long_term">{t('signals.horizon.long_term')}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="space-y-1">
                                <Label className="text-[11px]">{t('alerts.threshold')}</Label>
                                <Input
                                    type="number"
                                    step="any"
                                    value={thresholdValue}
                                    onChange={(e) => setThresholdValue(e.target.value)}
                                    className="num h-8 text-xs"
                                    required
                                />
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('alerts.notes')}</Label>
                            <Input value={notes} onChange={(e) => setNotes(e.target.value)} className="h-8 text-xs" />
                        </div>

                        <div className="flex items-end">
                            <Button type="submit" size="sm" className="h-8 w-full text-xs">
                                {t('app.save')}
                            </Button>
                        </div>
                    </div>
                </form>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('alerts.active_list')}</h2>
                        {alerts.length === 0 ? (
                            <EmptyState title={t('app.none')} />
                        ) : (
                            <div className="divide-border divide-y text-xs">
                                {alerts.map((a) => (
                                    <div key={a.id} className="flex items-center justify-between gap-2 py-2.5">
                                        <div>
                                            <Link href={`/stocks/${a.exchange_code}/${a.symbol}`} className="font-semibold hover:underline">
                                                {a.symbol} ({a.exchange_code})
                                            </Link>
                                            <span className="text-muted-foreground ml-2">{t(`alerts.type.${a.alert_type}`)}</span>
                                            {a.threshold_value && <span className="num ml-1 font-medium">{a.threshold_value}</span>}
                                            {a.horizon && <span className="ml-1 text-[11px]">({a.horizon})</span>}
                                            {a.notes && <p className="text-muted-foreground text-[11px]">{a.notes}</p>}
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-7 w-7"
                                                onClick={() => router.patch(`/alerts/${a.id}/toggle`, {}, { preserveScroll: true })}
                                                title={a.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                            >
                                                <Power className={`h-3.5 w-3.5 ${a.is_active ? 'text-[var(--nadi-gain-ink)]' : 'text-muted-foreground'}`} />
                                            </Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-7 w-7"
                                                onClick={() => router.delete(`/alerts/${a.id}`, { preserveScroll: true })}
                                            >
                                                <Trash2 className="h-3.5 w-3.5 text-[var(--nadi-loss-ink)]" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('alerts.events_feed')}</h2>
                        {events.length === 0 ? (
                            <EmptyState title={t('app.none')} />
                        ) : (
                            <div className="divide-border divide-y text-xs">
                                {events.map((ev) => (
                                    <div key={ev.id} className="flex items-start justify-between gap-2 py-2.5">
                                        <div>
                                            <div className="font-medium">{ev.message}</div>
                                            <span className="text-muted-foreground num text-[11px]">{formatDate(ev.triggered_date, locale)}</span>
                                        </div>
                                        {!ev.read_at && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-7 gap-1 px-2 text-[11px]"
                                                onClick={() => router.post(`/alerts/events/${ev.id}/read`, {}, { preserveScroll: true })}
                                            >
                                                <Check className="h-3 w-3" />
                                                <span>{t('alerts.mark_read')}</span>
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <div className="flex items-center gap-2">
                            <Calendar className="text-muted-foreground h-4 w-4" />
                            <h2 className="text-sm font-semibold">{t('alerts.calendar_actions')}</h2>
                        </div>
                        {corporateActions.length === 0 ? (
                            <EmptyState title={t('stocks.actions.empty')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="py-1.5 font-semibold">Ex-Date</th>
                                            <th className="py-1.5 font-semibold">Ticker</th>
                                            <th className="py-1.5 text-right font-semibold">Nominal</th>
                                            <th className="py-1.5 font-semibold">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {corporateActions.map((ca) => (
                                            <tr key={ca.id}>
                                                <td className="num py-2">{formatDate(ca.ex_date, locale)}</td>
                                                <td className="py-2 font-semibold">
                                                    <Link href={`/stocks/${ca.exchange_code}/${ca.symbol}`} className="hover:underline">
                                                        {ca.symbol} ({ca.exchange_code})
                                                    </Link>{' '}
                                                    {ca.is_demo && <DemoBadge />}
                                                </td>
                                                <td className="num py-2 text-right">
                                                    {ca.cash_amount
                                                        ? `${ca.declared_currency ?? ''} ${formatPrice(ca.cash_amount, ca.declared_currency ?? 'USD', locale)}`
                                                        : ca.action_type}
                                                </td>
                                                <td className="text-muted-foreground py-2">{ca.notes ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('alerts.calendar_holidays')}</h2>
                        {holidays.length === 0 ? (
                            <EmptyState title={t('dashboard.holidays_not_loaded')} />
                        ) : (
                            <div className="divide-border divide-y text-xs">
                                {holidays.map((h) => (
                                    <div key={h.id} className="flex items-center justify-between py-2">
                                        <span className="font-medium">
                                            {h.exchange_code} · {h.name}
                                        </span>
                                        <span className="num text-muted-foreground">{formatDate(h.holiday_date, locale)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
