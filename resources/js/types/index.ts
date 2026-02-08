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
    exchange_rate: string | null;
    from_account_id: number | null;
    to_account_id: number | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    account?: Account;
    category?: Category;
    from_account?: Account;
    to_account?: Account;
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
    installments: number | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    account?: Account;
    category?: Category;
    payments?: Payment[];
}

export interface Payment {
    id: number;
    user_id: number;
    account_id: number;
    category_id: number;
    recurring_payment_id: number | null;
    type: 'income' | 'expense';
    amount: string;
    currency: string;
    description: string | null;
    due_date: string;
    is_paid: boolean;
    paid_at: string | null;
    transaction_id: number | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    account?: Account;
    category?: Category;
    recurring_payment?: RecurringPayment;
    transaction?: Transaction;
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
    total_expected_income: number;
    total_paid: number;
    unpaid_count: number;
    paid_count: number;
    balance_after_planned: number;
}

export interface CategorySpending {
    category: Category;
    total: number;
    count: number;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash?: {
        success?: string;
        error?: string;
    };
};
