import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { formatCompact, formatDate, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useMemo, useRef, useState } from 'react';

echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, CanvasRenderer]);

export interface BenchmarkSeriesPoint {
    date: string;
    portfolio_index: string;
    portfolio_twr: string;
    benchmark_index: string;
    benchmark_return: string;
    excess_return: string;
    relative_drawdown: string;
}

export interface TwrDailyPoint {
    date: string;
    begin_equity: string;
    end_equity: string;
    external_cash_flow: string;
    daily_return: string;
    cumulative_twr: string;
    nav_index: string;
}

export interface PeriodReturnPoint {
    period: string;
    twr: string;
}

export interface RollingExcessPoint {
    date: string;
    excess_20d: string;
}

export interface AttributionComponents {
    local_price_effect_rep: string;
    local_price_pct: string | null;
    fx_effect_rep: string;
    fx_effect_pct: string | null;
    dividend_rep: string;
    dividend_pct: string | null;
    fee_tax_rep: string;
    fee_tax_pct: string | null;
    cash_fx_and_adjustments_rep: string;
    cash_fx_and_adjustments_pct: string | null;
    total_gain_rep: string;
    total_contribution_pct: string | null;
}

interface PerformanceChartsProps {
    twrDaily: TwrDailyPoint[];
    benchmarkSeries: BenchmarkSeriesPoint[];
    benchmarkCode: string;
    benchmarkCurrencyLabel: string;
    rollingExcess20d: RollingExcessPoint[];
    monthlyReturns: PeriodReturnPoint[];
    annualReturns: PeriodReturnPoint[];
    components: AttributionComponents;
    reportingCurrency: string;
    isProMode: boolean;
}

export function PerformanceCharts({
    twrDaily,
    benchmarkSeries,
    benchmarkCode,
    benchmarkCurrencyLabel,
    rollingExcess20d,
    monthlyReturns,
    annualReturns,
    components,
    reportingCurrency,
    isProMode,
}: PerformanceChartsProps) {
    const { t, locale } = useT();
    const curveRef = useRef<HTMLDivElement>(null);
    const periodRef = useRef<HTMLDivElement>(null);
    const driverRef = useRef<HTMLDivElement>(null);

    const [curveMode, setCurveMode] = useState<'normalized' | 'cumulative_pct' | 'rolling_excess'>('normalized');
    const [periodGranularity, setPeriodGranularity] = useState<'monthly' | 'annual'>('monthly');

    const activeCurveSeries = useMemo(() => {
        if (curveMode === 'rolling_excess') {
            return rollingExcess20d;
        }
        return benchmarkSeries.length > 0 ? benchmarkSeries : twrDaily;
    }, [curveMode, rollingExcess20d, benchmarkSeries, twrDaily]);

    // 1. Portfolio TWR vs Benchmark Curve (Normalized 100 / Cumulative % / Rolling 20D Excess)
    useEffect(() => {
        const el = curveRef.current;
        if (!el || activeCurveSeries.length === 0) {
            return;
        }

        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        if (curveMode === 'rolling_excess') {
            chart.setOption({
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: isDark ? '#192231' : '#FFFFFF',
                    borderColor: isDark ? '#283548' : '#E2E7EF',
                    textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                    formatter: (params: unknown) => {
                        const list = Array.isArray(params) ? (params as Array<{ axisValue?: string; value?: number }>) : [];
                        if (list.length === 0) return '';
                        const dt = formatDate(list[0].axisValue ?? '', locale);
                        return `<div><div style="font-weight:600;margin-bottom:4px">${dt}</div><div>${t('perf.chart.rolling_excess')}: <strong>${formatPct(list[0].value ?? 0, locale, true)}</strong></div></div>`;
                    },
                },
                grid: { left: 56, right: 18, top: 28, bottom: 28 },
                xAxis: {
                    type: 'category',
                    data: rollingExcess20d.map((p) => p.date),
                    axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                    axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
                },
                yAxis: {
                    type: 'value',
                    axisLabel: {
                        color: isDark ? '#9AA7BC' : '#5E6C80',
                        fontSize: 10,
                        formatter: (val: number) => `${(val * 100).toFixed(1)}%`,
                    },
                    splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
                },
                series: [
                    {
                        name: t('perf.chart.rolling_excess'),
                        type: 'line',
                        smooth: false,
                        showSymbol: false,
                        lineStyle: { width: 2, color: isDark ? '#B9A8E9' : '#534296' },
                        areaStyle: { color: isDark ? 'rgba(185, 168, 233, 0.14)' : 'rgba(83, 66, 150, 0.08)' },
                        markLine: {
                            silent: true,
                            symbol: 'none',
                            lineStyle: { type: 'dashed', color: isDark ? '#475569' : '#94A3B8' },
                            data: [{ yAxis: 0 }],
                        },
                        data: rollingExcess20d.map((p) => Number(p.excess_20d)),
                    },
                ],
            });
        } else {
            const hasBench = benchmarkSeries.length > 0;
            const dates = hasBench ? benchmarkSeries.map((p) => p.date) : twrDaily.map((p) => p.date);
            const portValues =
                curveMode === 'normalized'
                    ? hasBench
                        ? benchmarkSeries.map((p) => Number(p.portfolio_index))
                        : twrDaily.map((p) => Number(p.nav_index))
                    : hasBench
                      ? benchmarkSeries.map((p) => Number(p.portfolio_twr))
                      : twrDaily.map((p) => Number(p.cumulative_twr));

            const benchValues = hasBench
                ? curveMode === 'normalized'
                    ? benchmarkSeries.map((p) => Number(p.benchmark_index))
                    : benchmarkSeries.map((p) => Number(p.benchmark_return))
                : [];

            const series: Array<Record<string, unknown>> = [
                {
                    name: t('perf.chart.portfolio_twr'),
                    type: 'line',
                    smooth: false,
                    showSymbol: false,
                    lineStyle: { width: 2.2, color: isDark ? '#6ED3A3' : '#1F6A4A' },
                    areaStyle: { color: isDark ? 'rgba(110, 211, 163, 0.12)' : 'rgba(31, 106, 74, 0.08)' },
                    data: portValues,
                },
            ];

            if (hasBench) {
                series.push({
                    name: `${benchmarkCode} (${benchmarkCurrencyLabel})`,
                    type: 'line',
                    smooth: false,
                    showSymbol: false,
                    lineStyle: { width: 2, type: 'dashed', color: isDark ? '#C8E2F0' : '#534296' },
                    data: benchValues,
                });
            }

            chart.setOption({
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: isDark ? '#192231' : '#FFFFFF',
                    borderColor: isDark ? '#283548' : '#E2E7EF',
                    textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                    formatter: (params: unknown) => {
                        const list = Array.isArray(params)
                            ? (params as Array<{ axisValue?: string; seriesName?: string; value?: number }>)
                            : [];
                        if (list.length === 0) return '';
                        const dt = formatDate(list[0].axisValue ?? '', locale);
                        const lines = list.map((item) => {
                            const valStr =
                                curveMode === 'normalized'
                                    ? formatNumber(item.value ?? 0, locale, 2, 2)
                                    : formatPct(item.value ?? 0, locale, true);
                            return `<div>${item.seriesName}: <strong>${valStr}</strong></div>`;
                        });
                        return `<div><div style="font-weight:600;margin-bottom:4px">${dt}</div>${lines.join('')}</div>`;
                    },
                },
                legend: { top: 0, textStyle: { color: isDark ? '#CBD5E1' : '#475569', fontSize: 11 } },
                grid: { left: 56, right: 18, top: 34, bottom: 28 },
                xAxis: {
                    type: 'category',
                    data: dates,
                    axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                    axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
                },
                yAxis: {
                    type: 'value',
                    scale: true,
                    axisLabel: {
                        color: isDark ? '#9AA7BC' : '#5E6C80',
                        fontSize: 10,
                        formatter: (val: number) =>
                            curveMode === 'normalized' ? val.toFixed(0) : `${(val * 100).toFixed(1)}%`,
                    },
                    splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
                },
                series,
            });
        }

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [
        activeCurveSeries,
        curveMode,
        benchmarkSeries,
        twrDaily,
        benchmarkCode,
        benchmarkCurrencyLabel,
        rollingExcess20d,
        locale,
        t,
    ]);

    // 2. Period Returns (Monthly / Annual)
    const activePeriodRows = periodGranularity === 'monthly' ? monthlyReturns : annualReturns;
    useEffect(() => {
        const el = periodRef.current;
        if (!el || activePeriodRows.length === 0) {
            return;
        }

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
                    const list = Array.isArray(params) ? (params as Array<{ axisValue?: string; value?: number }>) : [];
                    if (list.length === 0) return '';
                    return `<div><div style="font-weight:600;margin-bottom:2px">${list[0].axisValue}</div><div>TWR: <strong>${formatPct(list[0].value ?? 0, locale, true)}</strong></div></div>`;
                },
            },
            grid: { left: 52, right: 16, top: 20, bottom: 28 },
            xAxis: {
                type: 'category',
                data: activePeriodRows.map((p) => p.period),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10 },
                axisLine: { lineStyle: { color: isDark ? '#283548' : '#E2E7EF' } },
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    color: isDark ? '#9AA7BC' : '#5E6C80',
                    fontSize: 10,
                    formatter: (val: number) => `${(val * 100).toFixed(1)}%`,
                },
                splitLine: { lineStyle: { color: isDark ? '#243044' : '#EEF2F7' } },
            },
            series: [
                {
                    name: 'TWR',
                    type: 'bar',
                    barMaxWidth: 32,
                    data: activePeriodRows.map((p) => {
                        const v = Number(p.twr);
                        return {
                            value: v,
                            itemStyle: {
                                color:
                                    v >= 0
                                        ? isDark
                                            ? '#6ED3A3'
                                            : '#1F6A4A'
                                        : isDark
                                          ? '#F0B9B2'
                                          : '#B43E32',
                                borderRadius: v >= 0 ? [3, 3, 0, 0] : [0, 0, 3, 3],
                            },
                        };
                    }),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [activePeriodRows, locale]);

    // 3. Return Driver Decomposition
    useEffect(() => {
        const el = driverRef.current;
        if (!el) {
            return;
        }

        const isDark = document.documentElement.classList.contains('dark');
        const chart = echarts.init(el);

        const items = [
            { label: t('perf.driver.local_price'), value: Number(components.local_price_effect_rep) },
            { label: t('perf.driver.fx'), value: Number(components.fx_effect_rep) },
            { label: t('perf.driver.dividends'), value: Number(components.dividend_rep) },
            { label: t('perf.driver.fees'), value: Number(components.fee_tax_rep) },
            { label: t('perf.driver.cash_other'), value: Number(components.cash_fx_and_adjustments_rep) },
        ];

        chart.setOption({
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                backgroundColor: isDark ? '#192231' : '#FFFFFF',
                borderColor: isDark ? '#283548' : '#E2E7EF',
                textStyle: { color: isDark ? '#E8EDF5' : '#263447', fontSize: 12 },
                formatter: (params: unknown) => {
                    const list = Array.isArray(params) ? (params as Array<{ name?: string; value?: number }>) : [];
                    if (list.length === 0) return '';
                    const v = list[0].value ?? 0;
                    const prefix = v > 0 ? '+' : '';
                    return `<div><div style="font-weight:600;margin-bottom:2px">${list[0].name}</div><div><strong>${prefix}${formatPrice(v, reportingCurrency, locale)}</strong></div></div>`;
                },
            },
            grid: { left: 56, right: 16, top: 18, bottom: 38 },
            xAxis: {
                type: 'category',
                data: items.map((i) => i.label),
                axisLabel: { color: isDark ? '#9AA7BC' : '#5E6C80', fontSize: 10, interval: 0 },
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
                    type: 'bar',
                    barMaxWidth: 36,
                    data: items.map((i) => ({
                        value: i.value,
                        itemStyle: {
                            color:
                                i.value >= 0
                                    ? isDark
                                        ? '#6ED3A3'
                                        : '#1F6A4A'
                                    : isDark
                                      ? '#F0B9B2'
                                      : '#B43E32',
                            borderRadius: i.value >= 0 ? [3, 3, 0, 0] : [0, 0, 3, 3],
                        },
                    })),
                },
            ],
        });

        const ro = new ResizeObserver(() => chart.resize());
        ro.observe(el);
        return () => {
            ro.disconnect();
            chart.dispose();
        };
    }, [components, reportingCurrency, locale, t]);

    return (
        <div className="space-y-4">
            {/* Normalized TWR vs Benchmark Curve */}
            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-semibold">{t('perf.chart.comparison_title')}</h2>
                        <p className="text-muted-foreground text-[11px]">{t('perf.chart.comparison_subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant={curveMode === 'normalized' ? 'secondary' : 'ghost'}
                            className="h-7 text-xs"
                            onClick={() => setCurveMode('normalized')}
                        >
                            {t('perf.chart.mode.normalized')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={curveMode === 'cumulative_pct' ? 'secondary' : 'ghost'}
                            className="h-7 text-xs"
                            onClick={() => setCurveMode('cumulative_pct')}
                        >
                            {t('perf.chart.mode.cumulative_pct')}
                        </Button>
                        {(isProMode || rollingExcess20d.length > 0) && (
                            <Button
                                type="button"
                                size="sm"
                                variant={curveMode === 'rolling_excess' ? 'secondary' : 'ghost'}
                                className="h-7 text-xs"
                                disabled={rollingExcess20d.length === 0}
                                onClick={() => setCurveMode('rolling_excess')}
                            >
                                {t('perf.chart.mode.rolling_excess')}
                            </Button>
                        )}
                    </div>
                </div>

                {activeCurveSeries.length === 0 ? (
                    <EmptyState title={t('perf.empty.no_chart')} />
                ) : (
                    <div ref={curveRef} className="h-[300px] w-full" />
                )}
            </section>

            {/* Period Returns & Driver Decomposition */}
            <div className="grid gap-4 lg:grid-cols-2">
                <section className="bg-card border-border rounded-md border p-4 space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('perf.chart.period_returns_title')}</h2>
                        <div className="flex items-center gap-1">
                            <Button
                                type="button"
                                size="sm"
                                variant={periodGranularity === 'monthly' ? 'secondary' : 'ghost'}
                                className="h-7 text-xs"
                                onClick={() => setPeriodGranularity('monthly')}
                            >
                                {t('perf.chart.monthly')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={periodGranularity === 'annual' ? 'secondary' : 'ghost'}
                                className="h-7 text-xs"
                                onClick={() => setPeriodGranularity('annual')}
                            >
                                {t('perf.chart.annual')}
                            </Button>
                        </div>
                    </div>

                    {activePeriodRows.length === 0 ? (
                        <EmptyState title={t('perf.empty.no_chart')} />
                    ) : (
                        <div ref={periodRef} className="h-[250px] w-full" />
                    )}
                </section>

                <section className="bg-card border-border rounded-md border p-4 space-y-3">
                    <div>
                        <h2 className="text-sm font-semibold">{t('perf.chart.drivers_title')}</h2>
                        <p className="text-muted-foreground text-[11px]">{t('perf.chart.drivers_subtitle')}</p>
                    </div>
                    <div ref={driverRef} className="h-[250px] w-full" />
                </section>
            </div>
        </div>
    );
}
