import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

interface ShortcutHandlers {
    onOpenPalette: () => void;
    onOpenHelp: () => void;
}

function isEditableElement(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }
    const tag = target.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
}

export function useKeyboardShortcuts({ onOpenPalette, onOpenHelp }: ShortcutHandlers) {
    const pendingChord = useRef<string | null>(null);
    const chordTimer = useRef<number | null>(null);

    useEffect(() => {
        const clearChord = () => {
            pendingChord.current = null;
            if (chordTimer.current !== null) {
                window.clearTimeout(chordTimer.current);
                chordTimer.current = null;
            }
        };

        const handleKeyDown = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                clearChord();
                onOpenPalette();
                return;
            }

            if (isEditableElement(event.target) || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                clearChord();
                onOpenPalette();
                return;
            }

            if (event.key === '?') {
                event.preventDefault();
                clearChord();
                onOpenHelp();
                return;
            }

            const key = event.key.toLowerCase();

            if (pendingChord.current === 'g') {
                clearChord();
                if (key === 'o') {
                    event.preventDefault();
                    router.visit('/dashboard');
                } else if (key === 'm') {
                    event.preventDefault();
                    router.visit('/markets');
                } else if (key === 'e') {
                    event.preventDefault();
                    router.visit('/stocks');
                } else if (key === 's') {
                    event.preventDefault();
                    router.visit('/settings/preferences');
                }
                return;
            }

            if (key === 'g') {
                pendingChord.current = 'g';
                chordTimer.current = window.setTimeout(clearChord, 900);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => {
            window.removeEventListener('keydown', handleKeyDown);
            clearChord();
        };
    }, [onOpenPalette, onOpenHelp]);
}
