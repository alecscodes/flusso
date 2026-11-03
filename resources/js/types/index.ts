export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    reset_date: number | null;
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
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    account?: Account;
    category?: Category;
    to_account?: Account;
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
    overdue: boolean;
    is_estimate: boolean;
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
