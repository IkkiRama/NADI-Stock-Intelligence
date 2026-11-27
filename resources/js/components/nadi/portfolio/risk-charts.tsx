import { EmptyState } from '@/components/nadi/empty-state';
import { formatDate, formatNumber, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, CanvasRenderer]);

export interface UnderwaterPoint {
    date: string;
    drawdown: string;
    wealth_index: string;
    peak_index: string;
}

export interface WeightComparisonRow {
    symbol: string;
    current_weight_pct: string;
    target_weight_pct: string;
    post_weight_pct: string;
}

export function UnderwaterDrawdownChart({ series }: { series: UnderwaterPoint[] }) {
    const { t, locale } = useT();
    const chartRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = chartRef.current;
        if (!el || series.length === 0) {
            return;
        }
        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        chart.setOption({
            tooltip: {
                trigger: 'axis',
                backgroundColor: isDark ? '#192231' : '#FFFFFF',
                borderColor: isDark ? '#283548' : '#E2E7EF',
                textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                formatter: (params: unknown) => {
                    const list = Array.isArray(params) ? (params as Array<{ dataIndex?: number; axisValue?: string }>) : [];
                    if (list.length === 0) return '';
                    const pt = series[list[0].dataIndex ?? 0];
                    if (!pt) return '';
                    return `<div><div style="font-weight:600;margin-bottom:4px">${formatDate(pt.date, locale)}</div><div>${t('risk.kpi.current_dd')}: <strong>${formatPct(pt.drawdown, locale, true)}</strong></div><div>${t('risk.dd.wealth_idx')}: ${formatNumber(pt.wealth_index, locale, 2, 2)} / Peak ${formatNumber(pt.peak_index, locale, 2, 2)}</div></div>`;
                },
            },
            grid: { left: 54, right: 16, top: 20, bottom: 28 },
            xAxis: {
                type: 'category',
                data: series.map((p) => p.date),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
            },
            yAxis: {
                type: 'value',
                max: 0,
                axisLabel: {
                    color: isDark ? '#9AA7BC' : '#5E6C80',
                    fontSize: 10,
                    formatter: (val: number) => `${(val * 100).toFixed(1)}%`,
                },
                splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
            },
            series: [
                {
                    name: t('risk.dd.underwater_title'),
                    type: 'line',
                    smooth: false,
                    showSymbol: false,
                    lineStyle: { width: 2, color: isDark ? '#F0B9B2' : '#B43E32' },
                    areaStyle: { color: isDark ? 'rgba(240, 185, 178, 0.22)' : 'rgba(180, 62, 50, 0.14)' },
                    markLine: {
                        silent: true,
                        symbol: 'none',
                        lineStyle: { type: 'dashed', color: isDark ? '#475569' : '#94A3B8' },
                        data: [{ yAxis: 0 }],
                    },
                    data: series.map((p) => Number(p.drawdown)),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [series, locale, t]);

    if (series.length === 0) {
        return <EmptyState title={t('risk.empty.no_drawdown')} />;
    }

    return <div ref={chartRef} className="h-[260px] w-full" />;
}

export function RebalanceWeightChart({ rows }: { rows: WeightComparisonRow[] }) {
    const { t, locale } = useT();
    const chartRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = chartRef.current;
        if (!el || rows.length === 0) {
            return;
        }
        const top = rows.slice(0, 14);
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
                    const list = Array.isArray(params)
                        ? (params as Array<{ axisValue?: string; seriesName?: string; value?: number }>)
                        : [];
                    if (list.length === 0) return '';
                    const lines = list.map(
                        (item) => `<div>${item.seriesName}: <strong>${formatNumber(item.value ?? 0, locale, 2, 2)}%</strong></div>`,
                    );
                    return `<div><div style="font-weight:600;margin-bottom:4px">${list[0].axisValue}</div>${lines.join('')}</div>`;
                },
            },
            legend: { top: 0, textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 } },
            grid: { left: 46, right: 16, top: 34, bottom: 28 },
            xAxis: {
                type: 'category',
                data: top.map((r) => r.symbol),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    color: isDark ? '#9AA7BC' : '#5E6C80',
                    fontSize: 10,
                    formatter: (val: number) => `${val}%`,
                },
                splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
            },
            series: [
                {
                    name: t('risk.reb.col_current_w'),
                    type: 'bar',
                    itemStyle: { color: isDark ? '#64748B' : '#94A3B8', borderRadius: [3, 3, 0, 0] },
                    data: top.map((r) => Number(r.current_weight_pct)),
                },
                {
                    name: t('risk.reb.col_target_w'),
                    type: 'bar',
                    itemStyle: { color: isDark ? '#B9A8E9' : '#534296', borderRadius: [3, 3, 0, 0] },
                    data: top.map((r) => Number(r.target_weight_pct)),
                },
                {
                    name: t('risk.reb.col_post_w'),
                    type: 'bar',
                    itemStyle: { color: isDark ? '#6ED3A3' : '#1F6A4A', borderRadius: [3, 3, 0, 0] },
                    data: top.map((r) => Number(r.post_weight_pct)),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [rows, locale, t]);

    if (rows.length === 0) {
        return <EmptyState title={t('risk.empty.no_targets')} />;
    }

    return <div ref={chartRef} className="h-[260px] w-full" />;
}
