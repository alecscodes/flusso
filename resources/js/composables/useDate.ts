const DATE_ONLY =
    /^(\d{4})-(\d{2})-(\d{2})(?:[T ]00:00:00(?:\.0+)?(?:Z|[+-]00:00)?)?$/;

/**
 * Date-only values ("2026-10-27", or the backend's midnight timestamps) are
 * parsed as local dates so they never shift a day. Date arguments are copied,
 * never mutated.
 */
function toDate(date: string | Date): Date {
    if (date instanceof Date) {
        return new Date(date);
    }

    const match = DATE_ONLY.exec(date);

    return match
        ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        : new Date(date);
}

function startOfToday(): Date {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return today;
}

export function useDate() {
    const formatDate = (
        date: string | Date,
        options?: Intl.DateTimeFormatOptions,
    ) =>
        new Intl.DateTimeFormat('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            ...options,
        }).format(toDate(date));

    const formatRelative = (date: string | Date) => {
        const target = toDate(date);
        target.setHours(0, 0, 0, 0);

        const diffDays = Math.round(
            (target.getTime() - startOfToday().getTime()) /
                (1000 * 60 * 60 * 24),
        );

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Tomorrow';
        if (diffDays === -1) return 'Yesterday';
        if (diffDays > 0 && diffDays <= 7) return `In ${diffDays} days`;
        if (diffDays < 0 && diffDays >= -7)
            return `${Math.abs(diffDays)} days ago`;

        return formatDate(date);
    };

    const formatMonthYear = (date: string | Date) =>
        new Intl.DateTimeFormat('en-US', {
            month: 'long',
            year: 'numeric',
        }).format(toDate(date));

    const isOverdue = (date: string | Date) => {
        const d = toDate(date);
        d.setHours(0, 0, 0, 0);

        return d < startOfToday();
    };

    const isDueToday = (date: string | Date) => {
        const d = toDate(date);
        const now = new Date();

        return (
            d.getDate() === now.getDate() &&
            d.getMonth() === now.getMonth() &&
            d.getFullYear() === now.getFullYear()
        );
    };

    const isDueSoon = (date: string | Date, days = 7) => {
        const d = toDate(date);
        d.setHours(0, 0, 0, 0);
        const today = startOfToday();
        const future = new Date(today);
        future.setDate(future.getDate() + days);

        return d >= today && d <= future;
    };

    /** "YYYY-MM-DD" in the user's local timezone, for <input type="date">. */
    const toInputFormat = (date: string | Date) => {
        const d = toDate(date);
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');

        return `${d.getFullYear()}-${month}-${day}`;
    };

    return {
        formatDate,
        formatRelative,
        formatMonthYear,
        isOverdue,
        isDueToday,
        isDueSoon,
        toInputFormat,
    };
}
