import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    if (typeof document === 'undefined') {
        return;
    }
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', isDark);
};

const getMediaQuery = () => (typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null);

const handleSystemThemeChange = () => {
    const currentAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';
    applyTheme(currentAppearance);
};

export function initializeTheme() {
    if (typeof window === 'undefined') {
        return;
    }
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';
    applyTheme(savedAppearance);
    getMediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(serverAppearance?: Appearance) {
    const [appearance, setAppearance] = useState<Appearance>(() => {
        if (typeof window !== 'undefined') {
            return (localStorage.getItem('appearance') as Appearance) || serverAppearance || 'system';
        }
        return serverAppearance || 'system';
    });

    const updateAppearance = useCallback((mode: Appearance) => {
        setAppearance(mode);
        if (typeof window !== 'undefined') {
            localStorage.setItem('appearance', mode);
        }
        applyTheme(mode);
    }, []);

    useEffect(() => {
        if (serverAppearance) {
            const local = localStorage.getItem('appearance') as Appearance | null;
            if (!local) {
                updateAppearance(serverAppearance);
            }
        }
        const mq = getMediaQuery();
        mq?.addEventListener('change', handleSystemThemeChange);
        return () => mq?.removeEventListener('change', handleSystemThemeChange);
    }, [serverAppearance, updateAppearance]);

    return { appearance, updateAppearance };
}
