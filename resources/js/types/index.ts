export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    reset_date: number | null;
    /** Opt-in: totals count advances only until they are settled. */
    count_advances_until_settled?: boolean;
    two_factor_confirmed_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Account {
    id: number;
    user_id: number;
    name: string;
    currency: string;
    balance: string;
    initial_balance: string;
    is_savings: boolean;
    alert_threshold: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    transactions?: Transaction[];
}

export interface Category {
    id: number;
    user_id: number;
    name: string;
    type: 'income' | 'expense';
    icon: string | null;
    color: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface Transaction {
    id: number;
    user_id: number;
    account_id: number;
    category_id: number | null;
    recurring_payment_id: number | null;
    type: 'income' | 'expense' | 'transfer';
    amount: string;
    currency: string;
    description: string | null;
    date: string;
    to_account_id: number | null;
    /** What arrived in the destination account (transfers only), in its currency. */
    to_amount: string | null;
    /** The person this was paid for (an advance) or came from (a repayment). */
    counterparty_id?: number | null;
    /** An expense paid for the counterparty: they owe it back. */
    is_advance?: boolean;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    account?: Account;
    category?: Category;
    to_account?: Account;
    counterparty?: { id: number; name: string } | null;
    files?: TransactionFile[];
}

export interface RecurringPayment {
    id: number;
    user_id: number;
    account_id: number;
    category_id: number;
    name: string;
    amount: string;
    currency: string;
    interval_type: 'days' | 'weeks' | 'months' | 'years';
    interval_value: number;
    start_date: string;
    end_date: string | null;
    /** 1 means the payment runs once. */
    installments: number | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    /** Only on the planned payments pages. */
    status?: 'active' | 'paused' | 'finished';
    account?: Account;
    category?: Category;
    payments?: Payment[];
    /** Only on the planned payments pages: the latest paid amount strays from the rule's by more than 10%. */
    price_change?: { rule_amount: string; last_paid_amount: string } | null;
}

/** A charge that looks like a subscription; the owner decides whether to plan it. */
export interface RecurringSuggestion {
    /** The normalised merchant, stable across charges. */
    key: string;
    label: string;
    interval_type: 'weeks' | 'months' | 'years';
    interval_value: number;
    amount: string;
    previous_amount: string;
    currency: string;
    account_id: number;
    category_id: number | null;
    occurrences: number;
    last_date: string;
    next_expected: string;
    price_jumped: boolean;
}

/** A suggestion the owner hid and can bring back. */
export interface DismissedSuggestion {
    key: string;
    label: string;
    kind: 'not_recurring' | 'temporary';
    /** Only for a temporary one: the day it comes back. */
    snooze_until: string | null;
}

export interface TransactionFile {
    id: number;
    transaction_id: number;
    filename: string;
    original_filename: string;
    mime_type: string;
    size: number;
    path: string;
    created_at: string;
    updated_at: string;
}

/** One occurrence of a planned payment: paid when it has a transaction, skipped when `skipped_at` is set. */
export interface Payment {
    id: number;
    user_id: number;
    account_id: number;
    category_id: number;
    recurring_payment_id: number;
    occurrence: number | null;
    type: 'income' | 'expense';
    amount: string;
    currency: string;
    description: string | null;
    due_date: string;
    transaction_id: number | null;
    is_overridden: boolean;
    skipped_at: string | null;
    created_at: string;
    updated_at: string;
    account?: Account;
    category?: Category;
    recurring_payment?: RecurringPayment;
    transaction?: Transaction | null;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    first_page_url: string;
    from: number;
    last_page: number;
    last_page_url: string;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number;
    total: number;
}

export interface Period {
    start: string;
    end: string;
}

export interface Summary {
    income: number;
    expenses: number;
    net: number;
}

export interface PaymentSummary {
    total_due: number;
    primary_currency: string;
}

export interface LiquidityPaymentLine {
    id: number;
    name: string;
    due_date: string;
    amount: number;
    currency: string;
    overdue: boolean;
    is_estimate: boolean;
    rate_missing: boolean;
}

export interface LiquidityForecast {
    account_id: number;
    account_name: string;
    currency: string;
    balance: number;
    balance_after: number;
    alert_threshold: number | null;
    below_threshold: boolean;
    due_total: number;
    shortfall: number;
    needed_by: string | null;
    negative_on: string | null;
    threshold_crossed_on: string | null;
    is_at_risk: boolean;
    is_estimate: boolean;
    has_unconverted_payments: boolean;
    days: number;
    message: string;
    payments: LiquidityPaymentLine[];
}

export interface LiquidityAlert {
    id: string;
    message: string;
    created_at: string;
}

export interface Liquidity {
    at_risk: LiquidityForecast[];
    alerts: LiquidityAlert[];
    days: number;
}

export interface CategorySpending {
    category: Category;
    total: number;
    count: number;
}

/** Per currency: what was advanced, settled and paid back. `balance` > 0 means they owe the owner, < 0 that they overpaid. */
export interface CounterpartyBalanceRow {
    currency: string;
    advanced: number;
    settled: number;
    outstanding: number;
    received: number;
    unallocated: number;
    balance: number;
}

export interface CounterpartyBalance {
    id: number;
    name: string;
    over_settled_count: number;
    balances: CounterpartyBalanceRow[];
}

export interface InsightsChange {
    amount: number;
    percent: number | null;
}

export interface InsightsSummary {
    income: number;
    expenses: number;
    net: number;
    transaction_count: number;
}

export interface InsightsCard {
    period: Period & { is_current: boolean };
    currency: string;
    is_estimate: boolean;
    summary: InsightsSummary;
    expense_change: InsightsChange;
    sentence: string;
}

export interface InsightsTopCategory {
    category_id: number;
    name: string;
    color: string | null;
    total: number;
    previous_total: number;
    change_amount: number;
    change_percent: number | null;
}

export interface InsightsUnusualCategory {
    category_id: number;
    name: string;
    total: number;
    average: number;
    ratio: number;
}

export interface InsightsExpense {
    id: number;
    date: string;
    description: string | null;
    category: string | null;
    amount: number;
    currency: string;
    amount_primary: number;
}

export interface InsightsPaymentLine {
    id: number;
    name: string;
    due_date: string;
    amount: number;
    currency: string;
    type: string;
}

export interface InsightsPerson {
    name: string;
    currency: string;
    balance: number;
}

export interface Insights extends InsightsCard {
    previous_period: Period;
    navigation: { previous: string; next: string | null };
    previous_summary: InsightsSummary;
    top_categories: InsightsTopCategory[];
    unusual_categories: InsightsUnusualCategory[];
    biggest_expenses: InsightsExpense[];
    payments: {
        days: number;
        overdue: InsightsPaymentLine[];
        upcoming: InsightsPaymentLine[];
        overdue_total: number;
        upcoming_total: number;
    };
    people: InsightsPerson[];
    liquidity: string | null;
}

export interface InsightsNarrative {
    text: string;
    source: 'ai' | 'template';
}
