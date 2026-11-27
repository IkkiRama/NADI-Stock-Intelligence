export function toIntlLocale(locale: string): string {
    return locale === 'en' ? 'en-US' : 'id-ID';
}

export function formatNumber(
    value: string | number | null | undefined,
    locale = 'id',
    minimumFractionDigits = 0,
    maximumFractionDigits = 2,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const num = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(num)) {
        return '—';
    }

    return new Intl.NumberFormat(toIntlLocale(locale), {
        minimumFractionDigits,
        maximumFractionDigits,
    }).format(num);
}

export function formatPrice(value: string | number | null | undefined, currency: string, locale = 'id'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const num = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(num)) {
        return '—';
    }

    const decimals = currency === 'IDR' ? (num < 10 ? 2 : 0) : num < 2 ? 3 : 2;

    return formatNumber(num, locale, decimals, decimals);
}

export function formatMoney(value: string | number | null | undefined, currency = 'IDR', locale = 'id'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const num = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(num)) {
        return '—';
    }

    const decimals = currency === 'IDR' ? 0 : 2;

    try {
        return new Intl.NumberFormat(toIntlLocale(locale), {
            style: 'currency',
            currency,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(num);
    } catch {
        return `${currency} ${formatNumber(num, locale, decimals, decimals)}`;
    }
}

export function formatPct(fraction: string | number | null | undefined, locale = 'id', withSign = true): string {
    if (fraction === null || fraction === undefined || fraction === '') {
        return '—';
    }
    const num = typeof fraction === 'number' ? fraction : Number(fraction);
    if (!Number.isFinite(num)) {
        return '—';
    }

    const formatted = new Intl.NumberFormat(toIntlLocale(locale), {
        style: 'percent',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: withSign ? 'exceptZero' : 'auto',
    }).format(num);

    return formatted;
}

export function formatCompact(value: string | number | null | undefined, locale = 'id'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const num = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(num)) {
        return '—';
    }

    return new Intl.NumberFormat(toIntlLocale(locale), {
        notation: 'compact',
        maximumFractionDigits: 2,
    }).format(num);
}

export function formatDate(dateStr: string | null | undefined, locale = 'id', timeZone = 'Asia/Jakarta'): string {
    if (!dateStr) {
        return '—';
    }

    const parsed = dateStr.length === 10 ? new Date(`${dateStr}T00:00:00Z`) : new Date(dateStr);
    if (Number.isNaN(parsed.getTime())) {
        return dateStr;
    }

    return new Intl.DateTimeFormat(toIntlLocale(locale), {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        timeZone: dateStr.length === 10 ? 'UTC' : timeZone,
    }).format(parsed);
}

export function formatTime(isoStr: string | null | undefined, locale = 'id', timeZone = 'UTC'): string {
    if (!isoStr) {
        return '—';
    }

    const parsed = new Date(isoStr);
    if (Number.isNaN(parsed.getTime())) {
        return isoStr;
    }

    return new Intl.DateTimeFormat(toIntlLocale(locale), {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone,
    }).format(parsed);
}

export function formatDateTime(isoStr: string | null | undefined, locale = 'id', timeZone = 'Asia/Jakarta'): string {
    if (!isoStr) {
        return '—';
    }

    const parsed = new Date(isoStr);
    if (Number.isNaN(parsed.getTime())) {
        return isoStr;
    }

    return new Intl.DateTimeFormat(toIntlLocale(locale), {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone,
    }).format(parsed);
}
