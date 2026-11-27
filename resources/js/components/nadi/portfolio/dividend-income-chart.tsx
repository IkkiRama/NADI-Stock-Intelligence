import { EmptyState } from '@/components/nadi/empty-state';
import { formatCompact, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

echarts.use([BarChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

export interface MonthlyRealizedDividend {
    month: string;
    realized_net_rep: string;
}

export interface MonthlyProjectedDividend {
    month: string;
    confirmed_net_rep: string;
    estimated_net_rep: string;
    total_projected_net_rep: string;
}

interface DividendIncomeChartProps {
    monthlyRealized: MonthlyRealizedDividend[];
    monthlyProjected: MonthlyProjectedDividend[];
    reportingCurrency: string;
}

export function DividendIncomeChart({
    monthlyRealized,
    monthlyProjected,
    reportingCurrency,
}: DividendIncomeChartProps) {
    const { t, locale } = useT();
    const chartRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = chartRef.current;
        if (!el || (monthlyRealized.length === 0 && monthlyProjected.length === 0)) {
            return;
        }
        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        const monthSet = Array.from(
            new Set([...monthlyRealized.map((m) => m.month), ...monthlyProjected.map((m) => m.month)]),
        ).sort();

        const realizedMap = new Map(monthlyRealized.map((m) => [m.month, Number(m.realized_net_rep)]));
        const confirmedMap = new Map(monthlyProjected.map((m) => [m.month, Number(m.confirmed_net_rep)]));
        const estimatedMap = new Map(monthlyProjected.map((m) => [m.month, Number(m.estimated_net_rep)]));

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
                    const lines = list
                        .filter((item) => Number(item.value ?? 0) > 0)
                        .map(
                            (item) =>
                                `<div>${item.seriesName}: <strong>${formatPrice(item.value ?? 0, reportingCurrency, locale)}</strong></div>`,
                        );
                    if (lines.length === 0) {
                        return `<div><strong>${list[0].axisValue}</strong><div>—</div></div>`;
                    }
                    return `<div><div style="font-weight:600;margin-bottom:4px">${list[0].axisValue}</div>${lines.join('')}</div>`;
                },
            },
            legend: { top: 0, textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 } },
            grid: { left: 56, right: 16, top: 34, bottom: 28 },
            xAxis: {
                type: 'category',
                data: monthSet,
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
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
                    name: t('div.chart.realized'),
                    type: 'bar',
                    stack: 'income',
                    itemStyle: { color: isDark ? '#6ED3A3' : '#1F6A4A', borderRadius: [3, 3, 0, 0] },
                    data: monthSet.map((m) => realizedMap.get(m) ?? 0),
                },
                {
                    name: t('div.chart.confirmed'),
                    type: 'bar',
                    stack: 'income',
                    itemStyle: { color: isDark ? '#C8E2F0' : '#2563EB', borderRadius: [3, 3, 0, 0] },
                    data: monthSet.map((m) => confirmedMap.get(m) ?? 0),
                },
                {
                    name: t('div.chart.estimated'),
                    type: 'bar',
                    stack: 'income',
                    itemStyle: { color: isDark ? '#B9A8E9' : '#7C69C4', borderRadius: [3, 3, 0, 0] },
                    data: monthSet.map((m) => estimatedMap.get(m) ?? 0),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [monthlyRealized, monthlyProjected, reportingCurrency, locale, t]);

    if (monthlyRealized.length === 0 && monthlyProjected.length === 0) {
        return <EmptyState title={t('div.empty.no_income')} />;
    }

    return <div ref={chartRef} className="h-[280px] w-full" />;
}
