import { computed } from 'vue';

const currencySymbols: Record<string, string> = {
    USD: '$',
    EUR: '€',
    GBP: '£',
    JPY: '¥',
    CHF: 'CHF',
    CAD: 'C$',
    AUD: 'A$',
    CNY: '¥',
    INR: '₹',
    BRL: 'R$',
    MXN: 'MX$',
    PLN: 'zł',
    SEK: 'kr',
    NOK: 'kr',
    DKK: 'kr',
    CZK: 'Kč',
    HUF: 'Ft',
    RON: 'lei',
    BGN: 'лв',
    HRK: 'kn',
    RUB: '₽',
    TRY: '₺',
    ZAR: 'R',
    KRW: '₩',
    SGD: 'S$',
    HKD: 'HK$',
    NZD: 'NZ$',
    THB: '฿',
    PHP: '₱',
    IDR: 'Rp',
    MYR: 'RM',
    VND: '₫',
};

const supportedCurrencies = computed(() => Object.keys(currencySymbols));

const formatters = new Map<string, Intl.NumberFormat>();

/** Intl formatters are costly to build and money is formatted on every row: build each one once. */
function formatter(currency: string, compact: boolean): Intl.NumberFormat {
    const key = `${currency}:${compact}`;
    let format = formatters.get(key);

    if (!format) {
        format = new Intl.NumberFormat(
            'en-US',
            compact
                ? {
                      style: 'currency',
                      currency,
                      notation: 'compact',
                      maximumFractionDigits: 1,
                  }
                : {
                      style: 'currency',
                      currency,
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2,
                  },
        );
        formatters.set(key, format);
    }

    return format;
}

function formatCurrency(
    amount: number | string,
    currency: string = 'EUR',
): string {
    const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;

    try {
        return formatter(currency, false).format(numAmount);
    } catch {
        return `${currencySymbols[currency] || currency}${numAmount.toFixed(2)}`;
    }
}

function formatCompact(
    amount: number | string,
    currency: string = 'EUR',
): string {
    const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;

    try {
        return formatter(currency, true).format(numAmount);
    } catch {
        const symbol = currencySymbols[currency] || currency;
        const abs = Math.abs(numAmount);
        if (abs >= 1000000)
            return `${symbol}${(numAmount / 1000000).toFixed(1)}M`;
        if (abs >= 1000) return `${symbol}${(numAmount / 1000).toFixed(1)}K`;
        return `${symbol}${numAmount.toFixed(0)}`;
    }
}

export function useCurrency() {
    return { formatCurrency, formatCompact, supportedCurrencies };
}
