export function useDate() {
    const toDate = (date: string | Date) =>
        typeof date === 'string' ? new Date(date) : date;

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

    const formatDateShort = (date: string | Date) =>
        new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
        }).format(toDate(date));

    const formatDateLong = (date: string | Date) =>
        new Intl.DateTimeFormat('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        }).format(toDate(date));

    const formatRelative = (date: string | Date) => {
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const target = toDate(date);
        target.setHours(0, 0, 0, 0);

        const diffDays = Math.round(
            (target.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
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
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        d.setHours(0, 0, 0, 0);
        return d < now;
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
        const now = new Date();
        const future = new Date();
        future.setDate(future.getDate() + days);
        return d >= now && d <= future;
    };

    const toInputFormat = (date: string | Date) =>
        toDate(date).toISOString().split('T')[0];

    const getMonthStart = (date = new Date()) =>
        new Date(date.getFullYear(), date.getMonth(), 1);

    const getMonthEnd = (date = new Date()) =>
        new Date(date.getFullYear(), date.getMonth() + 1, 0);

    return {
        formatDate,
        formatDateShort,
        formatDateLong,
        formatRelative,
        formatMonthYear,
        isOverdue,
        isDueToday,
        isDueSoon,
        toInputFormat,
        getMonthStart,
        getMonthEnd,
    };
}
