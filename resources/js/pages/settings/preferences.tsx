import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface PreferencesProps {
    preferences: {
        locale: 'id' | 'en';
        appearance: 'light' | 'dark' | 'system';
        density: 'compact' | 'comfortable';
        analysis_mode: 'simple' | 'pro';
        default_currency: string;
        default_market: string;
        default_timeframe: string;
        timezone: string;
    };
    options: {
        locales: string[];
        currencies: string[];
        exchanges: string[];
        timeframes: string[];
        timezones: string[];
    };
}

export default function Preferences({ preferences, options }: PreferencesProps) {
    const { t } = useT();
    const { updateAppearance } = useAppearance(preferences.appearance);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.nav.preferences'), href: '/settings/preferences' }];

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        ...preferences,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        updateAppearance(data.appearance);
        patch(route('preferences.update'), {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.nav.preferences')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.preferences.title')} description={t('settings.preferences.desc')} />

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.locale')}</Label>
                                <Select value={data.locale} onValueChange={(v) => setData('locale', v as 'id' | 'en')}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="id">Bahasa Indonesia (ID)</SelectItem>
                                        <SelectItem value="en">English (EN)</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.locale} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.appearance')}</Label>
                                <Select value={data.appearance} onValueChange={(v) => setData('appearance', v as 'light' | 'dark' | 'system')}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="light">{t('topbar.theme.light')}</SelectItem>
                                        <SelectItem value="dark">{t('topbar.theme.dark')}</SelectItem>
                                        <SelectItem value="system">{t('topbar.theme.system')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.appearance} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.density')}</Label>
                                <Select value={data.density} onValueChange={(v) => setData('density', v as 'compact' | 'comfortable')}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="comfortable">{t('topbar.density.comfortable')}</SelectItem>
                                        <SelectItem value="compact">{t('topbar.density.compact')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.density} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.analysis_mode')}</Label>
                                <Select value={data.analysis_mode} onValueChange={(v) => setData('analysis_mode', v as 'simple' | 'pro')}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="simple">{t('topbar.mode.simple')}</SelectItem>
                                        <SelectItem value="pro">{t('topbar.mode.pro')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.analysis_mode} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.currency')}</Label>
                                <Select value={data.default_currency} onValueChange={(v) => setData('default_currency', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.currencies.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {c}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.default_currency} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.market')}</Label>
                                <Select value={data.default_market} onValueChange={(v) => setData('default_market', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.exchanges.map((ex) => (
                                            <SelectItem key={ex} value={ex}>
                                                {ex}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.default_market} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.timeframe')}</Label>
                                <Select value={data.default_timeframe} onValueChange={(v) => setData('default_timeframe', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.timeframes.map((tf) => (
                                            <SelectItem key={tf} value={tf}>
                                                {tf}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.default_timeframe} />
                            </div>

                            <div className="space-y-1.5">
                                <Label>{t('settings.preferences.timezone')}</Label>
                                <Select value={data.timezone} onValueChange={(v) => setData('timezone', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.timezones.map((tz) => (
                                            <SelectItem key={tz} value={tz}>
                                                {tz}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.timezone} />
                            </div>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <Button type="submit" disabled={processing}>
                                {t('app.save')}
                            </Button>
                            {recentlySuccessful && (
                                <p className="text-xs font-medium text-[var(--nadi-gain-ink)]">{t('settings.preferences.saved')}</p>
                            )}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
