import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

export function useT() {
    const { translations = {}, locale = 'id' } = usePage<SharedData>().props;

    const t = useCallback(
        (key: string, replacements?: Record<string, string | number>): string => {
            let value = translations[key] ?? key;

            if (replacements) {
                for (const [k, v] of Object.entries(replacements)) {
                    value = value.replaceAll(`:${k}`, String(v));
                }
            }

            return value;
        },
        [translations],
    );

    return { t, locale };
}
