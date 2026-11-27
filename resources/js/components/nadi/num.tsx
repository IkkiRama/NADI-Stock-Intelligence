import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface NumProps {
    value: string | number | null | undefined;
    minDigits?: number;
    maxDigits?: number;
    className?: string;
}

export function Num({ value, minDigits = 0, maxDigits = 2, className }: NumProps) {
    const { locale } = useT();

    return <span className={cn('num', className)}>{formatNumber(value, locale, minDigits, maxDigits)}</span>;
}
