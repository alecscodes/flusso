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

/** One transaction read from a typed line: a proposal the owner reviews, nothing is saved. */
export interface QuickEntry {
    type: 'income' | 'expense';
    amount: string;
    account_id: number | null;
    category_id: number | null;
    description: string | null;
    date: string;
    /** Where the category came from; null without one. */
    source: 'history' | 'ai' | null;
}

export interface QuickEntryResult {
    entries: QuickEntry[];
    unparsed: string[];
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
