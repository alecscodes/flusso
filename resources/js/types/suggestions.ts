/** A transaction the owner books again and again, offered as a one-tap prefill. */
export interface TransactionShortcut {
    account_id: number;
    category_id: number | null;
    type: 'income' | 'expense';
    amount: string;
    description: string | null;
    count: number;
    boosted: boolean;
}

/** A category proposed for a description: only ever a suggestion, the owner confirms it. */
export interface CategorySuggestion {
    category_id: number;
    source: 'history' | 'ai';
    reason: string;
}

/** An uncategorised transaction on the clean-up page. */
export interface CleanupRow {
    id: number;
    type: 'income' | 'expense';
    amount: string;
    currency: string;
    description: string | null;
    date: string;
    account: { id: number; name: string };
    suggestion: CategorySuggestion | null;
}
