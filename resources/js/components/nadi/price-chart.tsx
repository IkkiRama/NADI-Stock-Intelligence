import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type OhlcvBar, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    AreaSeries,
    CandlestickSeries,
    ColorType,
    HistogramSeries,
    type IChartApi,
    LineSeries,
    type SeriesMarker,
    type Time,
    createChart,
    createSeriesMarkers,
} from 'lightweight-charts';
import { BarChart2, Download, LineChart, Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

export interface IndicatorPoint {
    date: string;
    value: number;
}

export interface MacdPoint {
    date: string;
    macd: number;
    signal: number | null;
    hist: number | null;
}

export interface IndicatorSeriesData {
    sma20?: IndicatorPoint[];
    sma50?: IndicatorPoint[];
    sma200?: IndicatorPoint[];
    ema20?: IndicatorPoint[];
    ema50?: IndicatorPoint[];
    bbUpper?: IndicatorPoint[];
    bbMiddle?: IndicatorPoint[];
    bbLower?: IndicatorPoint[];
    rsi14?: IndicatorPoint[];
    macd?: MacdPoint[];
    atr14?: IndicatorPoint[];
    obv?: IndicatorPoint[];
}

export interface CorporateActionMarkerInput {
    id: number;
    action_type: string;
    ex_date: string;
    cash_amount?: string | null;
    ratio_numerator?: string | null;
    ratio_denominator?: string | null;
}

export interface BenchmarkOverlayData {
    code: string;
    name: string;
    points: Array<{ date: string; close: string }>;
}

interface PriceChartProps {
    bars: OhlcvBar[];
    indicatorSeries?: IndicatorSeriesData;
    corporateActions?: CorporateActionMarkerInput[];
    benchmarkOverlay?: BenchmarkOverlayData | null;
    defaultRange?: string;
    height?: number;
    showVolume?: boolean;
    className?: string;
}

const RANGES = ['1M', '3M', '6M', 'YTD', '1Y'] as const;
type OverlayKey = 'sma20' | 'sma50' | 'sma200' | 'ema20' | 'bb';
type SubPaneKey = 'none' | 'rsi14' | 'macd' | 'atr14' | 'obv';

export function PriceChart({
    bars,
    indicatorSeries,
    corporateActions = [],
    benchmarkOverlay,
    defaultRange,
    height = 360,
    showVolume = true,
    className,
}: PriceChartProps) {
    const { t } = useT();
    const { settings } = usePage<SharedData>().props;
    const containerRef = useRef<HTMLDivElement>(null);
    const chartBoxRef = useRef<HTMLDivElement>(null);
    const subChartBoxRef = useRef<HTMLDivElement>(null);
    const mainChartRef = useRef<IChartApi | null>(null);

    const [chartType, setChartType] = useState<'candle' | 'line'>('candle');
    const [range, setRange] = useState<string>(() =>
        RANGES.includes((defaultRange || settings?.default_timeframe || '1Y') as (typeof RANGES)[number])
            ? defaultRange || settings?.default_timeframe || '1Y'
            : '1Y',
    );
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [overlays, setOverlays] = useState<Record<OverlayKey, boolean>>({
        sma20: true,
        sma50: false,
        sma200: false,
        ema20: false,
        bb: false,
    });
    const [subPane, setSubPane] = useState<SubPaneKey>('none');
    const [showBenchmark, setShowBenchmark] = useState(false);

    const hasIndicators = Boolean(indicatorSeries && (indicatorSeries.sma20?.length || indicatorSeries.rsi14?.length));

    const cutoffDate = useMemo(() => {
        if (bars.length === 0) {
            return '';
        }
        const latestStr = bars[bars.length - 1].date;
        const latest = new Date(`${latestStr}T00:00:00Z`);
        let cutoff = new Date(latest);

        if (range === '1M') {
            cutoff.setUTCMonth(cutoff.getUTCMonth() - 1);
        } else if (range === '3M') {
            cutoff.setUTCMonth(cutoff.getUTCMonth() - 3);
        } else if (range === '6M') {
            cutoff.setUTCMonth(cutoff.getUTCMonth() - 6);
        } else if (range === 'YTD') {
            cutoff = new Date(Date.UTC(latest.getUTCFullYear(), 0, 1));
        } else {
            return bars[0].date;
        }
        return cutoff.toISOString().slice(0, 10);
    }, [bars, range]);

    const filteredBars = useMemo(() => {
        if (!cutoffDate) {
            return bars;
        }
        const sliced = bars.filter((b) => b.date >= cutoffDate);
        return sliced.length > 0 ? sliced : bars;
    }, [bars, cutoffDate]);

    const filterPts = (pts?: IndicatorPoint[]) => {
        if (!pts || !cutoffDate) {
            return pts ?? [];
        }
        const sliced = pts.filter((p) => p.date >= cutoffDate);
        return sliced.length > 0 ? sliced : pts;
    };

    useEffect(() => {
        const handleFsChange = () => {
            setIsFullscreen(Boolean(document.fullscreenElement && document.fullscreenElement === containerRef.current));
        };
        document.addEventListener('fullscreenchange', handleFsChange);
        return () => document.removeEventListener('fullscreenchange', handleFsChange);
    }, []);

    useEffect(() => {
        const el = chartBoxRef.current;
        if (!el || filteredBars.length === 0) {
            return;
        }

        const readDark = () => document.documentElement.classList.contains('dark');
        const buildColors = (dark: boolean) => ({
            bg: dark ? '#192231' : '#FFFFFF',
            text: dark ? '#9AA7BC' : '#5E6C80',
            grid: dark ? '#243044' : '#EEF2F7',
            border: dark ? '#283548' : '#E2E7EF',
            up: dark ? '#6ED3A3' : '#1F6A4A',
            down: dark ? '#F49E95' : '#A33B32',
            line: dark ? '#C8E2F0' : '#263447',
            areaTop: dark ? 'rgba(200, 226, 240, 0.28)' : 'rgba(38, 52, 71, 0.16)',
            areaBottom: dark ? 'rgba(200, 226, 240, 0.0)' : 'rgba(38, 52, 71, 0.0)',
            sma20: '#534296',
            sma50: '#D97706',
            sma200: '#0284C7',
            ema20: '#16A34A',
            bb: '#64748B',
            bench: '#B9A8E9',
        });

        let colors = buildColors(readDark());
        const subHeight = subPane !== 'none' && hasIndicators ? 130 : 0;
        const targetHeight = isFullscreen ? Math.max(400, window.innerHeight - 210 - subHeight) : height;

        const chart: IChartApi = createChart(el, {
            width: el.clientWidth,
            height: targetHeight,
            layout: {
                background: { type: ColorType.Solid, color: colors.bg },
                textColor: colors.text,
            },
            grid: {
                vertLines: { color: colors.grid },
                horzLines: { color: colors.grid },
            },
            rightPriceScale: { borderColor: colors.border },
            leftPriceScale: { visible: showBenchmark && Boolean(benchmarkOverlay?.points.length), borderColor: colors.border },
            timeScale: { borderColor: colors.border, timeVisible: false },
        });
        mainChartRef.current = chart;

        const mainSeries =
            chartType === 'candle'
                ? chart.addSeries(CandlestickSeries, {
                      upColor: colors.up,
                      downColor: colors.down,
                      borderUpColor: colors.up,
                      borderDownColor: colors.down,
                      wickUpColor: colors.up,
                      wickDownColor: colors.down,
                  })
                : chart.addSeries(AreaSeries, {
                      lineColor: colors.line,
                      topColor: colors.areaTop,
                      bottomColor: colors.areaBottom,
                      lineWidth: 2,
                  });

        if (chartType === 'candle') {
            mainSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    open: Number(b.open),
                    high: Number(b.high),
                    low: Number(b.low),
                    close: Number(b.close),
                })),
            );
        } else {
            mainSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    value: Number(b.close),
                })),
            );
        }

        // Corporate action markers on valid bar dates
        const barDateSet = new Set(filteredBars.map((b) => b.date));
        const markers: SeriesMarker<Time>[] = corporateActions
            .filter((a) => barDateSet.has(a.ex_date))
            .sort((a, b) => a.ex_date.localeCompare(b.ex_date))
            .map((a) => ({
                time: a.ex_date as Time,
                position: a.action_type === 'cash_dividend' ? 'belowBar' : 'aboveBar',
                color: a.action_type === 'cash_dividend' ? colors.up : colors.sma50,
                shape: a.action_type === 'cash_dividend' ? 'arrowUp' : 'circle',
                text: a.action_type === 'cash_dividend' ? 'DIV' : 'SPLIT',
            }));

        if (markers.length > 0) {
            createSeriesMarkers(mainSeries, markers);
        }

        // Price overlays
        if (indicatorSeries) {
            const addLine = (pts: IndicatorPoint[] | undefined, color: string, lineWidth = 1, lineStyle = 0) => {
                const sliced = filterPts(pts);
                if (!sliced.length) {
                    return;
                }
                const s = chart.addSeries(LineSeries, {
                    color,
                    lineWidth: lineWidth as 1 | 2 | 3 | 4,
                    lineStyle,
                    priceLineVisible: false,
                    lastValueVisible: false,
                });
                s.setData(sliced.map((p) => ({ time: p.date, value: p.value })));
            };

            if (overlays.sma20) addLine(indicatorSeries.sma20, colors.sma20, 1);
            if (overlays.sma50) addLine(indicatorSeries.sma50, colors.sma50, 1);
            if (overlays.sma200) addLine(indicatorSeries.sma200, colors.sma200, 1);
            if (overlays.ema20) addLine(indicatorSeries.ema20, colors.ema20, 1);
            if (overlays.bb) {
                addLine(indicatorSeries.bbUpper, colors.bb, 1, 2);
                addLine(indicatorSeries.bbMiddle, colors.bb, 1, 1);
                addLine(indicatorSeries.bbLower, colors.bb, 1, 2);
            }
        }

        // Benchmark % comparison overlay on left price scale
        if (showBenchmark && benchmarkOverlay?.points.length) {
            const benchSliced = benchmarkOverlay.points.filter((p) => !cutoffDate || p.date >= cutoffDate);
            if (benchSliced.length > 1) {
                const base = Number(benchSliced[0].close) || 1;
                const benchSeries = chart.addSeries(LineSeries, {
                    priceScaleId: 'left',
                    color: colors.bench,
                    lineWidth: 2,
                    priceFormat: { type: 'percent' },
                    title: benchmarkOverlay.code,
                });
                benchSeries.setData(
                    benchSliced.map((p) => ({
                        time: p.date,
                        value: ((Number(p.close) - base) / base) * 100,
                    })),
                );
            }
        }

        if (showVolume && filteredBars.some((b) => b.volume > 0)) {
            const volSeries = chart.addSeries(HistogramSeries, {
                priceFormat: { type: 'volume' },
                priceScaleId: '',
            });
            volSeries.priceScale().applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });
            volSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    value: b.volume,
                    color: Number(b.close) >= Number(b.open) ? `${colors.up}55` : `${colors.down}55`,
                })),
            );
        }

        chart.timeScale().fitContent();

        // Optional synchronized sub-pane indicator chart
        let subChart: IChartApi | null = null;
        const subEl = subChartBoxRef.current;
        if (subEl && subPane !== 'none' && indicatorSeries) {
            subChart = createChart(subEl, {
                width: subEl.clientWidth,
                height: 130,
                layout: { background: { type: ColorType.Solid, color: colors.bg }, textColor: colors.text },
                grid: { vertLines: { color: colors.grid }, horzLines: { color: colors.grid } },
                rightPriceScale: { borderColor: colors.border },
                timeScale: { borderColor: colors.border, timeVisible: false },
            });

            if (subPane === 'rsi14' && indicatorSeries.rsi14?.length) {
                const rsiS = subChart.addSeries(LineSeries, { color: colors.sma20, lineWidth: 2 });
                rsiS.setData(filterPts(indicatorSeries.rsi14).map((p) => ({ time: p.date, value: p.value })));
            } else if (subPane === 'atr14' && indicatorSeries.atr14?.length) {
                const atrS = subChart.addSeries(LineSeries, { color: colors.sma50, lineWidth: 2 });
                atrS.setData(filterPts(indicatorSeries.atr14).map((p) => ({ time: p.date, value: p.value })));
            } else if (subPane === 'obv' && indicatorSeries.obv?.length) {
                const obvS = subChart.addSeries(AreaSeries, {
                    lineColor: colors.sma200,
                    topColor: 'rgba(2, 132, 199, 0.2)',
                    bottomColor: 'rgba(2, 132, 199, 0.0)',
                    lineWidth: 1,
                });
                obvS.setData(filterPts(indicatorSeries.obv).map((p) => ({ time: p.date, value: p.value })));
            } else if (subPane === 'macd' && indicatorSeries.macd?.length) {
                const pts = indicatorSeries.macd.filter((p) => !cutoffDate || p.date >= cutoffDate);
                const histS = subChart.addSeries(HistogramSeries, { priceLineVisible: false });
                histS.setData(
                    pts
                        .filter((p) => p.hist !== null)
                        .map((p) => ({
                            time: p.date,
                            value: p.hist as number,
                            color: (p.hist as number) >= 0 ? `${colors.up}88` : `${colors.down}88`,
                        })),
                );
                const lineS = subChart.addSeries(LineSeries, { color: colors.line, lineWidth: 1 });
                lineS.setData(pts.map((p) => ({ time: p.date, value: p.macd })));
                const sigS = subChart.addSeries(LineSeries, { color: colors.sma50, lineWidth: 1 });
                sigS.setData(pts.filter((p) => p.signal !== null).map((p) => ({ time: p.date, value: p.signal as number })));
            }

            subChart.timeScale().fitContent();
            chart.timeScale().subscribeVisibleLogicalRangeChange((r) => {
                if (r && subChart) {
                    subChart.timeScale().setVisibleLogicalRange(r);
                }
            });
        }

        const resizeObserver = new ResizeObserver((entries) => {
            for (const entry of entries) {
                if (entry.contentRect.width > 0) {
                    chart.applyOptions({ width: entry.contentRect.width, height: targetHeight });
                    subChart?.applyOptions({ width: entry.contentRect.width, height: 130 });
                }
            }
        });
        resizeObserver.observe(el);

        const themeObserver = new MutationObserver(() => {
            colors = buildColors(readDark());
            chart.applyOptions({
                layout: { background: { type: ColorType.Solid, color: colors.bg }, textColor: colors.text },
                grid: { vertLines: { color: colors.grid }, horzLines: { color: colors.grid } },
                rightPriceScale: { borderColor: colors.border },
                timeScale: { borderColor: colors.border },
            });
            subChart?.applyOptions({
                layout: { background: { type: ColorType.Solid, color: colors.bg }, textColor: colors.text },
                grid: { vertLines: { color: colors.grid }, horzLines: { color: colors.grid } },
                rightPriceScale: { borderColor: colors.border },
                timeScale: { borderColor: colors.border },
            });
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => {
            themeObserver.disconnect();
            resizeObserver.disconnect();
            mainChartRef.current = null;
            subChart?.remove();
            chart.remove();
        };
    }, [filteredBars, chartType, height, showVolume, isFullscreen, overlays, subPane, showBenchmark, indicatorSeries, corporateActions, benchmarkOverlay, cutoffDate, hasIndicators]);

    const toggleFullscreen = async () => {
        if (!containerRef.current) {
            return;
        }
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await containerRef.current.requestFullscreen();
        }
    };

    const exportPng = () => {
        const canvas = mainChartRef.current?.takeScreenshot();
        if (!canvas) {
            return;
        }
        const link = document.createElement('a');
        link.download = `nadi-chart-${new Date().toISOString().slice(0, 10)}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    };

    const toggleOverlay = (key: OverlayKey) => {
        setOverlays((prev) => ({ ...prev, [key]: !prev[key] }));
    };

    return (
        <div ref={containerRef} className={cn('bg-card border-border rounded-md border p-3', isFullscreen && 'flex h-screen flex-col p-5', className)}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-1">
                    {RANGES.map((r) => (
                        <Button
                            key={r}
                            type="button"
                            size="sm"
                            variant={range === r ? 'default' : 'ghost'}
                            className="h-7 px-2.5 text-xs"
                            onClick={() => setRange(r)}
                        >
                            {r}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant={chartType === 'candle' ? 'secondary' : 'ghost'}
                        className="h-7 gap-1 px-2 text-xs"
                        onClick={() => setChartType('candle')}
                    >
                        <BarChart2 className="h-3.5 w-3.5" />
                        <span>{t('chart.candlestick')}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={chartType === 'line' ? 'secondary' : 'ghost'}
                        className="h-7 gap-1 px-2 text-xs"
                        onClick={() => setChartType('line')}
                    >
                        <LineChart className="h-3.5 w-3.5" />
                        <span>{t('chart.line')}</span>
                    </Button>
                    <Button type="button" size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs" onClick={exportPng} title={t('chart.export_png')}>
                        <Download className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 px-2 text-xs"
                        onClick={toggleFullscreen}
                        title={isFullscreen ? t('chart.exit_fullscreen') : t('chart.fullscreen')}
                    >
                        {isFullscreen ? <Minimize2 className="h-3.5 w-3.5" /> : <Maximize2 className="h-3.5 w-3.5" />}
                    </Button>
                </div>
            </div>

            {hasIndicators && (
                <div className="border-border mb-3 flex flex-wrap items-center justify-between gap-2 border-y py-1.5 text-[11px]">
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-muted-foreground mr-1 font-medium">{t('chart.overlays')}:</span>
                        {(
                            [
                                ['sma20', 'SMA 20'],
                                ['sma50', 'SMA 50'],
                                ['sma200', 'SMA 200'],
                                ['ema20', 'EMA 20'],
                                ['bb', 'BB (20,2)'],
                            ] as Array<[OverlayKey, string]>
                        ).map(([k, label]) => (
                            <button
                                key={k}
                                type="button"
                                onClick={() => toggleOverlay(k)}
                                className={cn(
                                    'rounded border px-1.5 py-0.5 transition-colors',
                                    overlays[k] ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                        {benchmarkOverlay && benchmarkOverlay.points.length > 1 && (
                            <button
                                type="button"
                                onClick={() => setShowBenchmark((b) => !b)}
                                className={cn(
                                    'rounded border px-1.5 py-0.5 transition-colors',
                                    showBenchmark
                                        ? 'border-[var(--nadi-lavender-ink)] bg-[var(--nadi-lavender)]/30 text-[var(--nadi-lavender-ink)]'
                                        : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t('chart.benchmark_overlay')}: {benchmarkOverlay.code}
                            </button>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-muted-foreground mr-1 font-medium">{t('chart.subpane')}:</span>
                        {(
                            [
                                ['none', 'Off'],
                                ['rsi14', 'RSI 14'],
                                ['macd', 'MACD'],
                                ['atr14', 'ATR 14'],
                                ['obv', 'OBV'],
                            ] as Array<[SubPaneKey, string]>
                        ).map(([k, label]) => (
                            <button
                                key={k}
                                type="button"
                                onClick={() => setSubPane(k)}
                                className={cn(
                                    'rounded border px-1.5 py-0.5 transition-colors',
                                    subPane === k ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {filteredBars.length === 0 ? (
                <div className="text-muted-foreground flex h-[280px] items-center justify-center text-xs">{t('chart.no_data')}</div>
            ) : (
                <>
                    <div ref={chartBoxRef} className="w-full" />
                    {subPane !== 'none' && hasIndicators && <div ref={subChartBoxRef} className="border-border mt-2 w-full border-t pt-2" />}
                </>
            )}

            <p className="text-muted-foreground mt-2 text-[11px]">
                {t('chart.eod_notice')} · {t('chart.vwap_note')}
            </p>
        </div>
    );
}
