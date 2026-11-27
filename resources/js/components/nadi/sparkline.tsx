import { cn } from '@/lib/utils';

interface SparklineProps {
    values: Array<string | number>;
    positive?: boolean | null;
    width?: number;
    height?: number;
    className?: string;
}

export function Sparkline({ values, positive, width = 96, height = 28, className }: SparklineProps) {
    const nums = values.map((v) => Number(v)).filter((n) => Number.isFinite(n));

    if (nums.length < 2) {
        return <div style={{ width, height }} className={cn('border-border/50 rounded border border-dashed', className)} />;
    }

    const min = Math.min(...nums);
    const max = Math.max(...nums);
    const span = max - min || 1;
    const pad = 2;

    const points = nums
        .map((n, i) => {
            const x = pad + (i / (nums.length - 1)) * (width - pad * 2);
            const y = height - pad - ((n - min) / span) * (height - pad * 2);
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');

    const isUp = positive ?? nums[nums.length - 1] >= nums[0];
    const stroke = isUp ? 'var(--nadi-gain-ink)' : 'var(--nadi-loss-ink)';

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            fill="none"
            className={cn('shrink-0', className)}
            aria-hidden="true"
        >
            <polyline points={points} stroke={stroke} strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}
