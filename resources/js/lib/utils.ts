import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
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
