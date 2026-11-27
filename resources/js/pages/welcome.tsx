import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, BarChart3, Globe2, Server, ShieldCheck } from 'lucide-react';

export default function Welcome() {
    const { auth, demoMode } = usePage<SharedData>().props;
    const { t } = useT();

    return (
        <>
            <Head title="NADI — Global Stock Intelligence" />
            <div className="bg-background text-foreground min-h-screen">
                <header className="border-border bg-card border-b">
                    <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
                        <Link href={route('home')} className="flex items-center gap-2.5">
                            <div className="bg-primary text-primary-foreground flex h-8 w-8 items-center justify-center rounded-md">
                                <AppLogoIcon className="h-5 w-5" />
                            </div>
                            <div className="leading-tight">
                                <span className="block text-sm font-semibold tracking-tight">NADI</span>
                                <span className="text-muted-foreground block text-[10px] tracking-wider uppercase">Global Stock Intelligence</span>
                            </div>
                        </Link>

                        <nav className="flex items-center gap-2">
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={route('dashboard')}>
                                        <span>{t('welcome.cta_dashboard')}</span>
                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={route('login')}>{t('welcome.cta_login')}</Link>
                                    </Button>
                                    <Button asChild size="sm">
                                        <Link href={route('register')}>{t('welcome.cta_register')}</Link>
                                    </Button>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-16">
                    <div className="max-w-3xl space-y-5">
                        <span className="border-border bg-card text-muted-foreground inline-flex items-center gap-2 rounded border px-2.5 py-1 text-xs font-medium">
                            <span className="h-1.5 w-1.5 rounded-full bg-[var(--nadi-gain-ink)]" />
                            {t('welcome.badge')}
                        </span>

                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{t('welcome.headline')}</h1>

                        <p className="text-muted-foreground text-base leading-relaxed">{t('welcome.subheadline')}</p>

                        <div className="flex flex-wrap items-center gap-3 pt-2">
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={route('dashboard')}>
                                        <span>{t('welcome.cta_dashboard')}</span>
                                        <ArrowRight className="ml-1.5 h-4 w-4" />
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild>
                                        <Link href={route('register')}>
                                            <span>{t('welcome.cta_register')}</span>
                                            <ArrowRight className="ml-1.5 h-4 w-4" />
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline">
                                        <Link href={route('login')}>{t('welcome.cta_login')}</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="mt-12 grid gap-4 md:grid-cols-3">
                        <div className="border-border bg-card rounded-md border p-5">
                            <Globe2 className="text-[var(--nadi-ice-ink)] mb-3 h-5 w-5" />
                            <h2 className="text-sm font-semibold">{t('welcome.pillar.markets.title')}</h2>
                            <p className="text-muted-foreground mt-1.5 text-xs leading-relaxed">{t('welcome.pillar.markets.desc')}</p>
                        </div>
                        <div className="border-border bg-card rounded-md border p-5">
                            <BarChart3 className="text-[var(--nadi-gain-ink)] mb-3 h-5 w-5" />
                            <h2 className="text-sm font-semibold">{t('welcome.pillar.accounting.title')}</h2>
                            <p className="text-muted-foreground mt-1.5 text-xs leading-relaxed">{t('welcome.pillar.accounting.desc')}</p>
                        </div>
                        <div className="border-border bg-card rounded-md border p-5">
                            <Server className="text-[var(--nadi-lavender-ink)] mb-3 h-5 w-5" />
                            <h2 className="text-sm font-semibold">{t('welcome.pillar.hosting.title')}</h2>
                            <p className="text-muted-foreground mt-1.5 text-xs leading-relaxed">{t('welcome.pillar.hosting.desc')}</p>
                        </div>
                    </div>

                    {demoMode && (
                        <div className="mt-8 rounded-md border border-[var(--nadi-lavender)]/60 bg-[var(--nadi-lavender)]/12 p-4">
                            <div className="flex items-start gap-3">
                                <ShieldCheck className="text-[var(--nadi-lavender-ink)] mt-0.5 h-4 w-4 shrink-0" />
                                <div>
                                    <h3 className="text-xs font-semibold text-[var(--nadi-lavender-ink)]">{t('welcome.demo_notice_title')}</h3>
                                    <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{t('welcome.demo_notice_body')}</p>
                                </div>
                            </div>
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
