import { formatMoney, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface MoneyProps {
    value: string | number | null | undefined;
    currency?: string;
    mode?: 'money' | 'price';
    className?: string;
}

export function Money({ value, currency = 'IDR', mode = 'price', className }: MoneyProps) {
    const { locale } = useT();
    const formatted = mode === 'money' ? formatMoney(value, currency, locale) : formatPrice(value, currency, locale);

    return <span className={cn('num', className)}>{formatted}</span>;
}
