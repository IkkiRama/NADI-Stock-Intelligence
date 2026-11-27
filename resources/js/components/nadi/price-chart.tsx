import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type OhlcvBar, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AreaSeries, CandlestickSeries, ColorType, HistogramSeries, type IChartApi, createChart } from 'lightweight-charts';
import { BarChart2, LineChart, Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface PriceChartProps {
    bars: OhlcvBar[];
    defaultRange?: string;
    height?: number;
    showVolume?: boolean;
    className?: string;
}

const RANGES = ['1M', '3M', '6M', 'YTD', '1Y'] as const;

export function PriceChart({ bars, defaultRange, height = 360, showVolume = true, className }: PriceChartProps) {
    const { t } = useT();
    const { settings } = usePage<SharedData>().props;
    const containerRef = useRef<HTMLDivElement>(null);
    const chartBoxRef = useRef<HTMLDivElement>(null);

    const [chartType, setChartType] = useState<'candle' | 'line'>('candle');
    const [range, setRange] = useState<string>(() => (RANGES.includes((defaultRange || settings?.default_timeframe || '1Y') as (typeof RANGES)[number]) ? (defaultRange || settings?.default_timeframe || '1Y') : '1Y'));
    const [isFullscreen, setIsFullscreen] = useState(false);

    const filteredBars = useMemo(() => {
        if (bars.length === 0) {
            return [];
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
            return bars;
        }

        const cutoffStr = cutoff.toISOString().slice(0, 10);
        const sliced = bars.filter((b) => b.date >= cutoffStr);
        return sliced.length > 0 ? sliced : bars;
    }, [bars, range]);

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
        });

        let colors = buildColors(readDark());
        const targetHeight = isFullscreen ? Math.max(460, window.innerHeight - 130) : height;

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
            rightPriceScale: {
                borderColor: colors.border,
            },
            timeScale: {
                borderColor: colors.border,
                timeVisible: false,
            },
        });

        if (chartType === 'candle') {
            const candleSeries = chart.addSeries(CandlestickSeries, {
                upColor: colors.up,
                downColor: colors.down,
                borderUpColor: colors.up,
                borderDownColor: colors.down,
                wickUpColor: colors.up,
                wickDownColor: colors.down,
            });

            candleSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    open: Number(b.open),
                    high: Number(b.high),
                    low: Number(b.low),
                    close: Number(b.close),
                })),
            );
        } else {
            const areaSeries = chart.addSeries(AreaSeries, {
                lineColor: colors.line,
                topColor: colors.areaTop,
                bottomColor: colors.areaBottom,
                lineWidth: 2,
            });

            areaSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    value: Number(b.close),
                })),
            );
        }

        if (showVolume && filteredBars.some((b) => b.volume > 0)) {
            const volSeries = chart.addSeries(HistogramSeries, {
                priceFormat: { type: 'volume' },
                priceScaleId: '',
            });
            volSeries.priceScale().applyOptions({
                scaleMargins: { top: 0.78, bottom: 0 },
            });
            volSeries.setData(
                filteredBars.map((b) => ({
                    time: b.date,
                    value: b.volume,
                    color: Number(b.close) >= Number(b.open) ? `${colors.up}55` : `${colors.down}55`,
                })),
            );
        }

        chart.timeScale().fitContent();

        const resizeObserver = new ResizeObserver((entries) => {
            for (const entry of entries) {
                if (entry.contentRect.width > 0) {
                    chart.applyOptions({
                        width: entry.contentRect.width,
                        height: isFullscreen ? Math.max(460, window.innerHeight - 130) : height,
                    });
                }
            }
        });
        resizeObserver.observe(el);

        const themeObserver = new MutationObserver(() => {
            colors = buildColors(readDark());
            chart.applyOptions({
                layout: {
                    background: { type: ColorType.Solid, color: colors.bg },
                    textColor: colors.text,
                },
                grid: {
                    vertLines: { color: colors.grid },
                    horzLines: { color: colors.grid },
                },
                rightPriceScale: { borderColor: colors.border },
                timeScale: { borderColor: colors.border },
            });
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => {
            themeObserver.disconnect();
            resizeObserver.disconnect();
            chart.remove();
        };
    }, [filteredBars, chartType, height, showVolume, isFullscreen]);

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

    return (
        <div ref={containerRef} className={cn('bg-card border-border rounded-md border p-3', isFullscreen && 'flex h-screen flex-col p-5', className)}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
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

                <div className="flex items-center gap-1">
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

            {filteredBars.length === 0 ? (
                <div className="text-muted-foreground flex h-[280px] items-center justify-center text-xs">{t('chart.no_data')}</div>
            ) : (
                <div ref={chartBoxRef} className="w-full" />
            )}

            <p className="text-muted-foreground mt-2 text-[11px]">{t('chart.eod_notice')}</p>
        </div>
    );
}
