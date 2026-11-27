import { ChangeBadge } from '@/components/nadi/change-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCompact, formatNumber, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart, PieChart, TreemapChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef, useState } from 'react';

echarts.use([PieChart, BarChart, TreemapChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

export interface AllocationBucket {
    key: string;
    label: string;
    count: number;
    cost_basis: string;
    market_value: string;
    unrealized_pnl: string;
    weight_holdings_pct: string;
    weight_equity_pct: string;
}

interface AllocationPanelProps {
    allocations: Record<string, AllocationBucket[]>;
    reportingCurrency: string;
}

const DIMENSIONS = [
    'by_sector',
    'by_stock',
    'by_industry',
    'by_country',
    'by_exchange',
    'by_currency',
    'by_broker',
    'by_size_tier',
    'by_safety',
    'by_asset_class',
] as const;

const PALETTE = ['#534296', '#1F6A4A', '#0284C7', '#B9A8E9', '#6CC093', '#C8E2F0', '#F0B9B2', '#64748B', '#D97706', '#0D9488'];

export function AllocationPanel({ allocations, reportingCurrency }: AllocationPanelProps) {
    const { t, locale } = useT();
    const chartRef = useRef<HTMLDivElement>(null);
    const [dimension, setDimension] = useState<(typeof DIMENSIONS)[number]>('by_sector');
    const [chartType, setChartType] = useState<'donut' | 'bar' | 'treemap'>('donut');

    const rows = allocations[dimension] ?? [];

    useEffect(() => {
        const el = chartRef.current;
        if (!el || rows.length === 0) {
            return;
        }

        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        const commonTooltip = {
            backgroundColor: isDark ? '#192231' : '#FFFFFF',
            borderColor: isDark ? '#283548' : '#E2E7EF',
            textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
        };

        if (chartType === 'donut') {
            chart.setOption({
                tooltip: {
                    ...commonTooltip,
                    trigger: 'item',
                    formatter: (p: { name?: string; value?: number; percent?: number }) =>
                        `<strong>${p.name ?? ''}</strong><br/>${formatPrice(p.value ?? 0, reportingCurrency, locale)} (${p.percent ?? 0}%)`,
                },
                legend: {
                    bottom: 0,
                    type: 'scroll',
                    textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 },
                },
                series: [
                    {
                        type: 'pie',
                        radius: ['48%', '74%'],
                        center: ['50%', '44%'],
                        avoidLabelOverlap: true,
                        itemStyle: { borderColor: isDark ? '#192231' : '#FFFFFF', borderWidth: 2 },
                        label: { show: false },
                        color: PALETTE,
                        data: rows.map((r) => ({ name: r.label, value: Math.max(0, Number(r.market_value)) })),
                    },
                ],
            });
        } else if (chartType === 'bar') {
            const top = rows.slice(0, 12).reverse();
            chart.setOption({
                tooltip: {
                    ...commonTooltip,
                    trigger: 'axis',
                    formatter: (params: unknown) => {
                        const list = Array.isArray(params) ? (params as Array<{ name?: string; value?: number }>) : [];
                        if (!list.length) return '';
                        return `<strong>${list[0].name}</strong><br/>${formatPrice(list[0].value ?? 0, reportingCurrency, locale)}`;
                    },
                },
                grid: { left: 130, right: 20, top: 12, bottom: 24 },
                xAxis: {
                    type: 'value',
                    axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10, formatter: (v: number) => formatCompact(v, locale) },
                    splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
                },
                yAxis: {
                    type: 'category',
                    data: top.map((r) => r.label),
                    axisLabel: { color: isDark ? '#CBD5E1' : '#263447', fontSize: 11, width: 115, overflow: 'truncate' },
                },
                series: [
                    {
                        type: 'bar',
                        data: top.map((r, i) => ({
                            value: Math.max(0, Number(r.market_value)),
                            itemStyle: { color: PALETTE[i % PALETTE.length], borderRadius: [0, 3, 3, 0] },
                        })),
                    },
                ],
            });
        } else {
            chart.setOption({
                tooltip: {
                    ...commonTooltip,
                    formatter: (info: { name?: string; value?: number }) =>
                        `<strong>${info.name ?? ''}</strong><br/>${formatPrice(info.value ?? 0, reportingCurrency, locale)}`,
                },
                series: [
                    {
                        type: 'treemap',
                        roam: false,
                        nodeClick: false,
                        breadcrumb: { show: false },
                        itemStyle: { borderColor: isDark ? '#111827' : '#FFFFFF', borderWidth: 2, gapWidth: 2 },
                        data: rows.map((r, i) => ({
                            name: `${r.label}\n${r.weight_equity_pct}%`,
                            value: Math.max(1, Number(r.market_value)),
                            itemStyle: { color: PALETTE[i % PALETTE.length] },
                        })),
                    },
                ],
            });
        }

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [rows, chartType, reportingCurrency, locale]);

    return (
        <section className="bg-card border-border rounded-md border p-4 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{t('portfolio.alloc.title')}</h2>

                <div className="flex flex-wrap items-center gap-2">
                    <Select value={dimension} onValueChange={(v) => setDimension(v as (typeof DIMENSIONS)[number])}>
                        <SelectTrigger className="h-8 w-52 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {DIMENSIONS.map((d) => (
                                <SelectItem key={d} value={d}>
                                    {t(`portfolio.alloc.dim.${d}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex items-center gap-1 rounded-md border p-0.5">
                        {(['donut', 'bar', 'treemap'] as const).map((ct) => (
                            <Button
                                key={ct}
                                type="button"
                                size="sm"
                                variant={chartType === ct ? 'default' : 'ghost'}
                                className="h-6 px-2 text-[11px]"
                                onClick={() => setChartType(ct)}
                            >
                                {t(`portfolio.alloc.chart.${ct}`)}
                            </Button>
                        ))}
                    </div>
                </div>
            </div>

            {rows.length === 0 ? (
                <EmptyState title={t('heatmap.empty_portfolio')} />
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    <div ref={chartRef} className="h-[300px] w-full" />

                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr className="border-border border-b text-left">
                                    <th className="py-2 font-semibold">{t(`portfolio.alloc.dim.${dimension}`)}</th>
                                    <th className="py-2 text-right font-semibold">Cost Basis</th>
                                    <th className="py-2 text-right font-semibold">Nilai ({reportingCurrency})</th>
                                    <th className="py-2 text-right font-semibold">Unrealized</th>
                                    <th className="py-2 text-right font-semibold">Bobot Ekuitas</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {rows.map((r) => (
                                    <tr key={r.key}>
                                        <td className="py-2 font-medium">{r.label}</td>
                                        <td className="num py-2 text-right">
                                            <Money value={r.cost_basis} currency={reportingCurrency} />
                                        </td>
                                        <td className="num py-2 text-right font-semibold">
                                            <Money value={r.market_value} currency={reportingCurrency} />
                                        </td>
                                        <td className="py-2 text-right">
                                            <ChangeBadge change={r.unrealized_pnl} currency={reportingCurrency} showValue />
                                        </td>
                                        <td className="num py-2 text-right font-semibold">{formatNumber(r.weight_equity_pct, locale, 2, 2)}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </section>
    );
}
