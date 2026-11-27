import { formatCompact, formatDate, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

echarts.use([LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

export interface StrategyCurvePoint {
    date: string;
    equity: string;
    buy_hold_equity: string;
    drawdown_pct: string;
}

interface StrategyEquityChartProps {
    data: StrategyCurvePoint[];
    currency: string;
    mode: 'equity' | 'drawdown';
}

export function StrategyEquityChart({ data, currency, mode }: StrategyEquityChartProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const { t, locale } = useT();

    useEffect(() => {
        if (!containerRef.current || data.length === 0) {
            return;
        }

        const chart = echarts.init(containerRef.current);
        const styles = getComputedStyle(document.documentElement);
        const textMuted = styles.getPropertyValue('--muted-foreground').trim() || '#5E6C80';
        const borderColor = styles.getPropertyValue('--border').trim() || '#E2E7EF';

        const dates = data.map((d) => formatDate(d.date, locale));

        if (mode === 'drawdown') {
            const ddVals = data.map((d) => Number(d.drawdown_pct) * 100);
            chart.setOption({
                animationDuration: 250,
                tooltip: {
                    trigger: 'axis',
                    valueFormatter: (val: unknown) => formatPct(Number(val ?? 0) / 100, locale),
                },
                grid: { top: 24, left: 56, right: 16, bottom: 28 },
                xAxis: {
                    type: 'category',
                    data: dates,
                    axisLabel: { color: textMuted, fontSize: 10 },
                    axisLine: { lineStyle: { color: borderColor } },
                },
                yAxis: {
                    type: 'value',
                    max: 0,
                    axisLabel: { color: textMuted, fontSize: 10, formatter: (v: number) => `${v.toFixed(1)}%` },
                    splitLine: { lineStyle: { color: borderColor, type: 'dashed' } },
                },
                series: [
                    {
                        name: t('strategy_lab.chart.drawdown_curve'),
                        type: 'line',
                        showSymbol: false,
                        data: ddVals,
                        lineStyle: { width: 2, color: '#B43C3E' },
                        areaStyle: { color: 'rgba(180, 60, 62, 0.14)' },
                    },
                ],
            });
        } else {
            const stratVals = data.map((d) => Number(d.equity));
            const bhVals = data.map((d) => Number(d.buy_hold_equity));
            chart.setOption({
                animationDuration: 250,
                tooltip: {
                    trigger: 'axis',
                    valueFormatter: (val: unknown) => `${currency} ${formatCompact(Number(val ?? 0), locale)}`,
                },
                legend: {
                    top: 0,
                    textStyle: { color: textMuted, fontSize: 11 },
                },
                grid: { top: 34, left: 64, right: 16, bottom: 28 },
                xAxis: {
                    type: 'category',
                    data: dates,
                    axisLabel: { color: textMuted, fontSize: 10 },
                    axisLine: { lineStyle: { color: borderColor } },
                },
                yAxis: {
                    type: 'value',
                    scale: true,
                    axisLabel: {
                        color: textMuted,
                        fontSize: 10,
                        formatter: (v: number) => formatCompact(v, locale),
                    },
                    splitLine: { lineStyle: { color: borderColor, type: 'dashed' } },
                },
                series: [
                    {
                        name: t('strategy_lab.chart.strategy_curve'),
                        type: 'line',
                        showSymbol: false,
                        data: stratVals,
                        lineStyle: { width: 2.2, color: '#337574' },
                    },
                    {
                        name: t('strategy_lab.chart.buy_hold_curve'),
                        type: 'line',
                        showSymbol: false,
                        data: bhVals,
                        lineStyle: { width: 1.8, type: 'dashed', color: '#4A5F7D' },
                    },
                ],
            });
        }

        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(containerRef.current);

        return () => {
            observer.disconnect();
            chart.dispose();
        };
    }, [data, currency, mode, locale, t]);

    return <div ref={containerRef} className="h-[280px] w-full" />;
}
