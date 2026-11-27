import { formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface ChangeBadgeProps {
    change?: string | number | null;
    changePct?: string | number | null;
    currency?: string;
    showValue?: boolean;
    pill?: boolean;
    className?: string;
}

export function ChangeBadge({ change, changePct, currency = 'IDR', showValue = false, pill = false, className }: ChangeBadgeProps) {
    const { locale } = useT();
    const refVal = changePct ?? change;
    const numeric = refVal === null || refVal === undefined || refVal === '' ? null : Number(refVal);

    if (numeric === null || !Number.isFinite(numeric)) {
        return <span className={cn('num text-muted-foreground text-xs', className)}>—</span>;
    }

    const isPositive = numeric > 0;
    const isNegative = numeric < 0;
    const symbol = isPositive ? '▲' : isNegative ? '▼' : '•';
    const srText = isPositive ? 'Naik' : isNegative ? 'Turun' : 'Tetap';

    const colorClass = isPositive
        ? 'text-[var(--nadi-gain-ink)]'
        : isNegative
          ? 'text-[var(--nadi-loss-ink)]'
          : 'text-muted-foreground';

    const pillClass = pill
        ? isPositive
            ? 'bg-[var(--nadi-gain-bg)] px-1.5 py-0.5 rounded'
            : isNegative
              ? 'bg-[var(--nadi-loss-bg)] px-1.5 py-0.5 rounded'
              : 'bg-muted px-1.5 py-0.5 rounded'
        : '';

    const formattedValue =
        showValue && change !== null && change !== undefined
            ? `${Number(change) > 0 ? '+' : ''}${formatPrice(change, currency, locale)} `
            : '';

    return (
        <span className={cn('num inline-flex items-center gap-1 text-xs font-medium', colorClass, pillClass, className)}>
            <span aria-hidden="true" className="text-[10px] leading-none">
                {symbol}
            </span>
            <span className="sr-only">{srText}</span>
            <span>
                {formattedValue}
                {changePct !== null && changePct !== undefined ? formatPct(changePct, locale, true) : ''}
            </span>
        </span>
    );
}
