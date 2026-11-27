import { formatCompact, formatDate } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

echarts.use([BarChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

export interface BrokerDailyTrendPoint {
    date: string;
    foreign_net: string;
    local_inst_net: string;
    retail_net: string;
    total_turnover: string;
}

interface BrokerFlowChartProps {
    data: BrokerDailyTrendPoint[];
    currency: string;
}

export function BrokerFlowChart({ data, currency }: BrokerFlowChartProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const chartRef = useRef<echarts.ECharts | null>(null);
    const { t, locale } = useT();

    useEffect(() => {
        if (!containerRef.current || data.length === 0) {
            return;
        }

        const chart = echarts.init(containerRef.current);
        chartRef.current = chart;

        const styles = getComputedStyle(document.documentElement);
        const textMuted = styles.getPropertyValue('--muted-foreground').trim() || '#5E6C80';
        const borderColor = styles.getPropertyValue('--border').trim() || '#E2E7EF';

        const dates = data.map((d) => formatDate(d.date, locale));
        const foreignVals = data.map((d) => Number(d.foreign_net));
        const localInstVals = data.map((d) => Number(d.local_inst_net));
        const retailVals = data.map((d) => Number(d.retail_net));

        chart.setOption({
            animationDuration: 250,
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                valueFormatter: (val: unknown) => `${currency} ${formatCompact(Number(val ?? 0), locale)}`,
            },
            legend: {
                top: 0,
                textStyle: { color: textMuted, fontSize: 11 },
            },
            grid: {
                top: 34,
                left: 64,
                right: 16,
                bottom: 28,
            },
            xAxis: {
                type: 'category',
                data: dates,
                axisLabel: { color: textMuted, fontSize: 10 },
                axisLine: { lineStyle: { color: borderColor } },
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    color: textMuted,
                    fontSize: 10,
                    formatter: (val: number) => formatCompact(val, locale),
                },
                splitLine: { lineStyle: { color: borderColor, type: 'dashed' } },
            },
            series: [
                {
                    name: t('broker_intel.category.foreign_institutional'),
                    type: 'bar',
                    data: foreignVals,
                    itemStyle: { color: '#4A5F7D', borderRadius: [2, 2, 0, 0] },
                },
                {
                    name: t('broker_intel.category.local_institutional'),
                    type: 'bar',
                    data: localInstVals,
                    itemStyle: { color: '#337574', borderRadius: [2, 2, 0, 0] },
                },
                {
                    name: t('broker_intel.category.local_retail'),
                    type: 'bar',
                    data: retailVals,
                    itemStyle: { color: '#6E5A9E', borderRadius: [2, 2, 0, 0] },
                },
            ],
        });

        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(containerRef.current);

        return () => {
            observer.disconnect();
            chart.dispose();
            chartRef.current = null;
        };
    }, [data, currency, locale, t]);

    return <div ref={containerRef} className="h-[260px] w-full" />;
}
