import { DataFreshness } from '@/components/nadi/data-freshness';
import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { TreemapChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { Compass, Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

echarts.use([TreemapChart, TooltipComponent, CanvasRenderer]);

interface HeatmapStock {
    id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    currency: string;
    country_code: string;
    sector: string;
    close: string | null;
    market_cap: string | null;
    market_cap_idr: string | null;
    volume: number;
    value_traded: string | null;
    change_pct: string | null;
    return_1w: string | null;
    return_1m: string | null;
    return_3m: string | null;
    return_1y: string | null;
    volatility_20d: string | null;
    overall_score: string | null;
    as_of: string | null;
    is_demo: boolean;
}

interface HeatmapProps {
    sectors: Array<{ sector: string; children: HeatmapStock[] }>;
    exchanges: Array<{ code: string; name: string }>;
    filters: { scope: 'market' | 'watchlist'; exchange: string };
    asOf: string | null;
    isDemo: boolean;
}

type SizeMetric = 'market_cap_idr' | 'value_traded' | 'volume';
type ColorMetric = 'change_pct' | 'return_1w' | 'return_1m' | 'return_3m' | 'return_1y' | 'volatility_20d' | 'overall_score';

function interpolateColor(metric: ColorMetric, rawValue: number | null, isDark: boolean): string {
    if (rawValue === null || !Number.isFinite(rawValue)) {
        return isDark ? '#283548' : '#E2E7EF';
    }

    if (metric === 'overall_score') {
        if (rawValue >= 75) return isDark ? '#1F6A4A' : '#7BC49D';
        if (rawValue >= 60) return isDark ? '#2B5F4B' : '#B7DFC9';
        if (rawValue >= 45) return isDark ? '#2A3A4F' : '#C8E2F0';
        return isDark ? '#7E312B' : '#F0B9B2';
    }

    if (metric === 'volatility_20d') {
        if (rawValue >= 0.45) return isDark ? '#8B352E' : '#E49087';
        if (rawValue >= 0.30) return isDark ? '#704935' : '#F0B9B2';
        if (rawValue >= 0.20) return isDark ? '#2B4861' : '#C8E2F0';
        return isDark ? '#1F5D42' : '#B7DFC9';
    }

    // Return metrics (fractional)
    if (rawValue >= 0.03) return isDark ? '#1B6B49' : '#6CC093';
    if (rawValue > 0.005) return isDark ? '#245843' : '#B7DFC9';
    if (rawValue > -0.005) return isDark ? '#2B3648' : '#E6ECF3';
    if (rawValue > -0.03) return isDark ? '#6E3532' : '#F0B9B2';
    return isDark ? '#91342E' : '#DF877E';
}

export default function Heatmap({ sectors, exchanges, filters, asOf, isDemo }: HeatmapProps) {
    const { t, locale } = useT();
    const wrapperRef = useRef<HTMLDivElement>(null);
    const chartRef = useRef<HTMLDivElement>(null);

    const [sizeBy, setSizeBy] = useState<SizeMetric>('market_cap_idr');
    const [colorBy, setColorBy] = useState<ColorMetric>('change_pct');
    const [selectedSector, setSelectedSector] = useState<string>('ALL');
    const [isFullscreen, setIsFullscreen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.heatmap'), href: '/heatmap' }];

    const visibleSectors = useMemo(() => {
        if (selectedSector === 'ALL') {
            return sectors;
        }
        return sectors.filter((s) => s.sector === selectedSector);
    }, [sectors, selectedSector]);

    useEffect(() => {
        const onFs = () => setIsFullscreen(Boolean(document.fullscreenElement && document.fullscreenElement === wrapperRef.current));
        document.addEventListener('fullscreenchange', onFs);
        return () => document.removeEventListener('fullscreenchange', onFs);
    }, []);

    useEffect(() => {
        const el = chartRef.current;
        if (!el || visibleSectors.length === 0) {
            return;
        }

        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        const treemapData = visibleSectors.map((sec) => ({
            name: sec.sector,
            children: sec.children.map((stock) => {
                const rawSize =
                    sizeBy === 'market_cap_idr'
                        ? Number(stock.market_cap_idr ?? stock.market_cap ?? 1)
                        : sizeBy === 'value_traded'
                          ? Number(stock.value_traded ?? 1)
                          : Number(stock.volume || 1);

                const rawColorVal = stock[colorBy] !== null ? Number(stock[colorBy]) : null;
                const color = interpolateColor(colorBy, rawColorVal, isDark);

                const metricLabel =
                    colorBy === 'overall_score'
                        ? formatNumber(rawColorVal, locale, 1, 1)
                        : formatPct(rawColorVal, locale, colorBy !== 'volatility_20d');

                return {
                    name: stock.symbol,
                    value: Math.max(1, rawSize),
                    stock,
                    metricLabel,
                    itemStyle: {
                        color,
                        borderColor: isDark ? '#111827' : '#FFFFFF',
                        borderWidth: 1.5,
                    },
                    label: {
                        color: isDark ? '#F1F5F9' : '#1E293B',
                        formatter: () => `${stock.symbol}\n${metricLabel}`,
                    },
                };
            }),
        }));

        chart.setOption({
            tooltip: {
                backgroundColor: isDark ? '#192231' : '#FFFFFF',
                borderColor: isDark ? '#283548' : '#E2E7EF',
                textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                formatter: (info: { data?: { stock?: HeatmapStock; metricLabel?: string; name?: string } }) => {
                    const s = info.data?.stock;
                    if (!s) {
                        return `<strong>${info.data?.name ?? ''}</strong>`;
                    }
                    return `
                        <div style="min-width:190px">
                            <div style="font-weight:600">${s.symbol} · ${s.exchange_code}</div>
                            <div style="font-size:11px;opacity:.8;margin-bottom:6px">${s.name}</div>
                            <div>Harga: <strong>${formatPrice(s.close, s.currency, locale)}</strong></div>
                            <div>1D: <strong>${formatPct(s.change_pct, locale)}</strong> | Score: <strong>${formatNumber(s.overall_score, locale, 1, 1)}</strong></div>
                            <div>MCap (IDR): <strong>${formatCompact(s.market_cap_idr, locale)}</strong></div>
                            <div style="font-size:10px;opacity:.7;margin-top:4px">${s.is_demo ? 'Demo' : 'Imported'} · ${s.as_of ?? ''}</div>
                        </div>
                    `;
                },
            },
            series: [
                {
                    type: 'treemap',
                    roam: false,
                    nodeClick: false,
                    breadcrumb: { show: false },
                    upperLabel: {
                        show: true,
                        height: 22,
                        color: isDark ? '#CBD5E1' : '#263447',
                        backgroundColor: isDark ? '#1E293B' : '#F1F5F9',
                        fontSize: 11,
                        fontWeight: 600,
                    },
                    itemStyle: {
                        borderColor: isDark ? '#111827' : '#FFFFFF',
                        gapWidth: 2,
                    },
                    levels: [
                        {
                            itemStyle: { borderWidth: 2, gapWidth: 3 },
                        },
                    ],
                    data: treemapData,
                },
            ],
        });

        chart.on('click', (params) => {
            const payload = params.data as { stock?: HeatmapStock } | null | undefined;
            const s = payload?.stock;
            if (s) {
                router.visit(`/stocks/${s.exchange_code}/${s.symbol}`);
            }
        });

        const resizeObserver = new ResizeObserver(() => chart.resize());
        resizeObserver.observe(el);

        return () => {
            resizeObserver.disconnect();
            chart.dispose();
        };
    }, [visibleSectors, sizeBy, colorBy, locale, isFullscreen]);

    const toggleFullscreen = async () => {
        if (!wrapperRef.current) return;
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await wrapperRef.current.requestFullscreen();
        }
    };

    const setScope = (scope: 'market' | 'watchlist') => {
        router.get('/heatmap', { scope, exchange: filters.exchange }, { preserveState: true });
    };

    const setExchange = (exchange: string) => {
        router.get('/heatmap', { scope: filters.scope, exchange }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('heatmap.title')} />

            <div ref={wrapperRef} className={cn('space-y-4', isFullscreen && 'bg-background h-screen overflow-auto p-5')}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">{t('heatmap.title')}</h1>
                        <p className="text-muted-foreground text-xs">{t('heatmap.subtitle')}</p>
                        <DataFreshness asOf={asOf} isDemo={isDemo} className="mt-1" />
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant={filters.scope === 'market' ? 'default' : 'outline'}
                            className="h-8 text-xs"
                            onClick={() => setScope('market')}
                        >
                            {t('heatmap.scope.market')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={filters.scope === 'watchlist' ? 'default' : 'outline'}
                            className="h-8 text-xs"
                            onClick={() => setScope('watchlist')}
                        >
                            {t('heatmap.scope.watchlist')}
                        </Button>
                        <Button type="button" size="sm" variant="outline" disabled className="h-8 text-xs" title={t('app.coming_soon')}>
                            {t('heatmap.scope.portfolio_disabled')}
                        </Button>
                    </div>
                </div>

                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.exchange} onValueChange={setExchange}>
                            <SelectTrigger className="h-8 w-36 text-xs">
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

                        <Select value={selectedSector} onValueChange={setSelectedSector}>
                            <SelectTrigger className="h-8 w-48 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('heatmap.all_sectors')}</SelectItem>
                                {sectors.map((s) => (
                                    <SelectItem key={s.sector} value={s.sector}>
                                        {s.sector}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={sizeBy} onValueChange={(v) => setSizeBy(v as SizeMetric)}>
                            <SelectTrigger className="h-8 w-44 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="market_cap_idr">{t('heatmap.size.market_cap')}</SelectItem>
                                <SelectItem value="value_traded">{t('heatmap.size.value_traded')}</SelectItem>
                                <SelectItem value="volume">{t('heatmap.size.volume')}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={colorBy} onValueChange={(v) => setColorBy(v as ColorMetric)}>
                            <SelectTrigger className="h-8 w-40 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="change_pct">{t('heatmap.color.change_pct')}</SelectItem>
                                <SelectItem value="return_1w">{t('heatmap.color.return_1w')}</SelectItem>
                                <SelectItem value="return_1m">{t('heatmap.color.return_1m')}</SelectItem>
                                <SelectItem value="return_3m">{t('heatmap.color.return_3m')}</SelectItem>
                                <SelectItem value="return_1y">{t('heatmap.color.return_1y')}</SelectItem>
                                <SelectItem value="volatility_20d">{t('heatmap.color.volatility_20d')}</SelectItem>
                                <SelectItem value="overall_score">{t('heatmap.color.overall_score')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="text-muted-foreground hidden items-center gap-1.5 text-[11px] sm:flex">
                            <span className="inline-block h-2.5 w-3 rounded-xs bg-[#DF877E]" />
                            <span>▼</span>
                            <span className="inline-block h-2.5 w-3 rounded-xs bg-[#E6ECF3] dark:bg-[#2B3648]" />
                            <span>•</span>
                            <span className="inline-block h-2.5 w-3 rounded-xs bg-[#6CC093]" />
                            <span>▲</span>
                        </div>
                        <Button type="button" size="sm" variant="ghost" className="h-8 px-2" onClick={toggleFullscreen}>
                            {isFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                        </Button>
                    </div>
                </div>

                {visibleSectors.length === 0 ? (
                    <EmptyState
                        icon={Compass}
                        title={filters.scope === 'watchlist' ? t('heatmap.empty_watchlist') : t('stocks.empty_title')}
                        actionLabel={t('dashboard.watchlist.cta')}
                        actionHref="/stocks"
                    />
                ) : (
                    <div className="bg-card border-border rounded-md border p-2">
                        <div ref={chartRef} className={cn('w-full', isFullscreen ? 'h-[calc(100vh-200px)]' : 'h-[560px]')} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
