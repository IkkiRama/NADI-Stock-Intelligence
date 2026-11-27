import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { formatCompact, formatDate, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useMemo, useRef, useState } from 'react';

echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

export interface PortfolioHistoryPoint {
    date: string;
    invested_cost: string;
    market_value: string;
    cash_balance: string;
    net_contributions: string;
    total_equity: string;
    unrealized_pnl: string;
}

export interface HoldingCostComparisonItem {
    symbol: string;
    total_cost_reporting: string;
    market_value_reporting: string;
}

interface CostVsMarketChartProps {
    historySeries: PortfolioHistoryPoint[];
    holdings: HoldingCostComparisonItem[];
    reportingCurrency: string;
}

const RANGES = ['1M', '3M', '6M', 'YTD', '1Y'] as const;

export function CostVsMarketChart({ historySeries, holdings, reportingCurrency }: CostVsMarketChartProps) {
    const { t, locale } = useT();
    const historyRef = useRef<HTMLDivElement>(null);
    const barRef = useRef<HTMLDivElement>(null);

    const [range, setRange] = useState<(typeof RANGES)[number]>('1Y');
    const [curveMode, setCurveMode] = useState<'cost_vs_market' | 'equity_vs_contrib'>('cost_vs_market');

    const slicedHistory = useMemo(() => {
        if (historySeries.length === 0 || range === '1Y') {
            return historySeries;
        }
        const latestStr = historySeries[historySeries.length - 1].date;
        const latest = new Date(`${latestStr}T00:00:00Z`);
        let cutoff = new Date(latest);
        if (range === '1M') cutoff.setUTCMonth(cutoff.getUTCMonth() - 1);
        else if (range === '3M') cutoff.setUTCMonth(cutoff.getUTCMonth() - 3);
        else if (range === '6M') cutoff.setUTCMonth(cutoff.getUTCMonth() - 6);
        else if (range === 'YTD') cutoff = new Date(Date.UTC(latest.getUTCFullYear(), 0, 1));

        const cutoffStr = cutoff.toISOString().slice(0, 10);
        const filtered = historySeries.filter((p) => p.date >= cutoffStr);
        return filtered.length > 0 ? filtered : historySeries;
    }, [historySeries, range]);

    useEffect(() => {
        const el = historyRef.current;
        if (!el || slicedHistory.length === 0) {
            return;
        }
        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        const primaryName =
            curveMode === 'cost_vs_market' ? t('portfolio.kpi.market_value') : t('portfolio.kpi.total_equity');
        const secondaryName =
            curveMode === 'cost_vs_market' ? t('portfolio.kpi.invested_cost') : t('portfolio.kpi.net_contributions');

        chart.setOption({
            tooltip: {
                trigger: 'axis',
                backgroundColor: isDark ? '#192231' : '#FFFFFF',
                borderColor: isDark ? '#283548' : '#E2E7EF',
                textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                formatter: (params: unknown) => {
                    const list = Array.isArray(params) ? (params as Array<{ axisValue?: string; seriesName?: string; value?: number }>) : [];
                    if (list.length === 0) return '';
                    const dt = formatDate(list[0].axisValue ?? '', locale);
                    const lines = list.map(
                        (item) => `<div>${item.seriesName}: <strong>${formatPrice(item.value ?? 0, reportingCurrency, locale)}</strong></div>`,
                    );
                    return `<div><div style="font-weight:600;margin-bottom:4px">${dt}</div>${lines.join('')}</div>`;
                },
            },
            legend: {
                top: 0,
                textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 },
            },
            grid: { left: 56, right: 16, top: 34, bottom: 28 },
            xAxis: {
                type: 'category',
                data: slicedHistory.map((p) => p.date),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
            },
            yAxis: {
                type: 'value',
                scale: true,
                axisLabel: {
                    color: isDark ? '#9AA7BC' : '#5E6C80',
                    fontSize: 10,
                    formatter: (val: number) => formatCompact(val, locale),
                },
                splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
            },
            series: [
                {
                    name: primaryName,
                    type: 'line',
                    smooth: false,
                    showSymbol: false,
                    lineStyle: { width: 2, color: isDark ? '#6ED3A3' : '#1F6A4A' },
                    areaStyle: { color: isDark ? 'rgba(110, 211, 163, 0.14)' : 'rgba(31, 106, 74, 0.10)' },
                    data: slicedHistory.map((p) =>
                        Number(curveMode === 'cost_vs_market' ? p.market_value : p.total_equity),
                    ),
                },
                {
                    name: secondaryName,
                    type: 'line',
                    smooth: false,
                    showSymbol: false,
                    lineStyle: { width: 2, type: 'dashed', color: isDark ? '#C8E2F0' : '#534296' },
                    data: slicedHistory.map((p) =>
                        Number(curveMode === 'cost_vs_market' ? p.invested_cost : p.net_contributions),
                    ),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [slicedHistory, curveMode, reportingCurrency, locale, t]);

    useEffect(() => {
        const el = barRef.current;
        if (!el || holdings.length === 0) {
            return;
        }
        const top = holdings.slice(0, 10);
        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        chart.setOption({
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                backgroundColor: isDark ? '#192231' : '#FFFFFF',
                borderColor: isDark ? '#283548' : '#E2E7EF',
                textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                formatter: (params: unknown) => {
                    const list = Array.isArray(params) ? (params as Array<{ axisValue?: string; seriesName?: string; value?: number }>) : [];
                    if (list.length === 0) return '';
                    const lines = list.map(
                        (item) => `<div>${item.seriesName}: <strong>${formatPrice(item.value ?? 0, reportingCurrency, locale)}</strong></div>`,
                    );
                    return `<div><div style="font-weight:600;margin-bottom:4px">${list[0].axisValue}</div>${lines.join('')}</div>`;
                },
            },
            legend: { top: 0, textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 } },
            grid: { left: 56, right: 16, top: 34, bottom: 28 },
            xAxis: {
                type: 'category',
                data: top.map((h) => h.symbol),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 11 },
                axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    color: isDark ? '#9AA7BC' : '#5E6C80',
                    fontSize: 10,
                    formatter: (val: number) => formatCompact(val, locale),
                },
                splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
            },
            series: [
                {
                    name: 'Cost Basis',
                    type: 'bar',
                    itemStyle: { color: isDark ? '#64748B' : '#94A3B8', borderRadius: [3, 3, 0, 0] },
                    data: top.map((h) => Number(h.total_cost_reporting)),
                },
                {
                    name: 'Market Value',
                    type: 'bar',
                    itemStyle: { color: isDark ? '#6ED3A3' : '#1F6A4A', borderRadius: [3, 3, 0, 0] },
                    data: top.map((h) => Number(h.market_value_reporting)),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [holdings, reportingCurrency, locale]);

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-sm font-semibold">{t('portfolio.chart.history_title')}</h2>
                    <div className="flex flex-wrap items-center gap-1">
                        {RANGES.map((r) => (
                            <Button
                                key={r}
                                type="button"
                                size="sm"
                                variant={range === r ? 'default' : 'ghost'}
                                className="h-6 px-2 text-[11px]"
                                onClick={() => setRange(r)}
                            >
                                {r}
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant={curveMode === 'cost_vs_market' ? 'secondary' : 'ghost'}
                        className="h-7 text-xs"
                        onClick={() => setCurveMode('cost_vs_market')}
                    >
                        {t('portfolio.chart.mode.cost_vs_market')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={curveMode === 'equity_vs_contrib' ? 'secondary' : 'ghost'}
                        className="h-7 text-xs"
                        onClick={() => setCurveMode('equity_vs_contrib')}
                    >
                        {t('portfolio.chart.mode.equity_vs_contrib')}
                    </Button>
                </div>

                {slicedHistory.length === 0 ? (
                    <EmptyState title={t('portfolio.chart.no_history')} />
                ) : (
                    <div ref={historyRef} className="h-[280px] w-full" />
                )}
            </section>

            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                <h2 className="text-sm font-semibold">{t('portfolio.chart.holdings_title')}</h2>
                {holdings.length === 0 ? (
                    <EmptyState title={t('heatmap.empty_portfolio')} />
                ) : (
                    <div ref={barRef} className="h-[316px] w-full" />
                )}
            </section>
        </div>
    );
}
