import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** Touch devices: focusing a text field there raises a keyboard that covers half the screen. */
export function isCoarsePointer(): boolean {
    return window.matchMedia('(pointer: coarse)').matches;
}

let scrollLocks = 0;

/**
 * Stops the page behind a dialog from scrolling until the returned function is
 * called. Locks stack, so the page scrolls again only after the last one is released.
 */
export function lockPageScroll(): () => void {
    let released = false;

    if (scrollLocks++ === 0) {
        document.body.style.overflow = 'hidden';
    }

    return () => {
        if (released) {
            return;
        }

        released = true;

        if (--scrollLocks === 0) {
            document.body.style.overflow = '';
        }
    };
}

export function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export function debounce<T extends (...args: unknown[]) => unknown>(
    fn: T,
    delay: number,
): (...args: Parameters<T>) => void {
    let timeoutId: ReturnType<typeof setTimeout>;
    return (...args: Parameters<T>) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

const singleIntervalLabels: Record<string, string> = {
    days: 'Daily',
    weeks: 'Weekly',
    months: 'Monthly',
    years: 'Yearly',
};

/** "Monthly" for 1 month, "Every 3 weeks" otherwise. */
export function intervalLabel(value: number, type: string): string {
    return (
        (value === 1 && singleIntervalLabels[type]) || `Every ${value} ${type}`
    );
}
