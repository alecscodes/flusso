<?php

namespace App\Services;

use App\Ai\Agents\CategorySuggestionAgent;
use App\Enums\AiFeature;
use App\Enums\TransactionType;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Support\DescriptionMatcher;
use ArrayAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Suggests a category for a description. The owner's own history comes first (a
 * fuzzy match against what they categorised before); only without a clear
 * history match is the model asked, and only with the descriptions and category
 * names it needs. Nothing here writes: the caller shows the suggestion and the
 * owner confirms it.
 */
class CategorySuggestionService
{
    /** How many of the most recent categorised transactions are compared against. */
    public const HISTORY_LIMIT = 500;

    /** Below this similarity a past description is not a match at all. */
    public const MIN_SIMILARITY = 0.75;

    /** A shorter description (after normalising) is too little to go on. */
    public const MIN_LENGTH = 3;

    /** The best category must have at least this many times the support of the runner-up. */
    public const CLEAR_WINNER_RATIO = 2.0;

    public function __construct(private readonly AiManager $ai) {}

    /**
     * @return array{category_id: int, source: 'history'|'ai', reason: string}|null
     */
    public function suggest(User $user, string $description, TransactionType $type): ?array
    {
        if ($type === TransactionType::Transfer) {
            return null;
        }

        return $this->fromHistory($this->history($user, $type), $description)
            ?? $this->fromAi($user, $description, $type);
    }

    /**
     * The owner's most recent categorised descriptions of one type, each with how often it was used.
     * Archived categories are left out and so are categories of the other kind.
     *
     * @return Collection<int, array{normalized: string, description: string, category_id: int, count: int}>
     */
    public function history(User $user, TransactionType $type): Collection
    {
        $entries = $user->transactions()
            ->where('type', $type->value)
            ->whereNotNull('description')
            ->whereIn('category_id', $user->categories()->where('type', $type->value)->select('id'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['description', 'category_id'])
            ->groupBy(fn ($transaction) => $transaction->category_id.'|'.DescriptionMatcher::normalize($transaction->description))
            ->map(fn (Collection $group): array => [
                'normalized' => DescriptionMatcher::normalize($group->first()->description),
                'description' => (string) $group->first()->description,
                'category_id' => (int) $group->first()->category_id,
                'count' => $group->count(),
            ])
            ->filter(fn (array $entry) => $entry['normalized'] !== '')
            ->values()
            ->all();

        return $this->historyEntries($entries);
    }

    /**
     * Keeps the declared shape: a collection's value type is invariant, so the narrower
     * types PHPStan infers from the query would not match the declared return type.
     *
     * @param  array<int, array{normalized: string, description: string, category_id: int, count: int}>  $entries
     * @return Collection<int, array{normalized: string, description: string, category_id: int, count: int}>
     */
    private function historyEntries(array $entries): Collection
    {
        return new Collection($entries);
    }

    /**
     * The category the owner's history clearly points to, or null when nothing matches or
     * two categories are about equally likely. Exact matches (ignoring case, diacritics and
     * punctuation) win over merely similar ones.
     *
     * @param  Collection<int, array{normalized: string, description: string, category_id: int, count: int}>  $history
     * @return array{category_id: int, source: 'history', reason: string}|null
     */
    public function fromHistory(Collection $history, ?string $description): ?array
    {
        $query = DescriptionMatcher::normalize($description);

        if (strlen($query) < self::MIN_LENGTH) {
            return null;
        }

        $candidates = $history
            ->map(fn (array $entry): array => [...$entry, 'score' => DescriptionMatcher::similarity($query, $entry['normalized'])])
            ->filter(fn (array $entry) => $entry['score'] >= self::MIN_SIMILARITY);

        $exact = $candidates->filter(fn (array $entry) => $entry['score'] >= 1.0);
        $candidates = $exact->isNotEmpty() ? $exact : $candidates;

        $ranked = $candidates
            ->groupBy('category_id')
            ->map(fn (Collection $group): array => [
                'support' => $group->sum(fn (array $entry) => $entry['score'] * $entry['count']),
                'best' => $group->sortByDesc('score')->first(),
            ])
            ->sortByDesc('support')
            ->values();

        if ($ranked->isEmpty()) {
            return null;
        }

        if ($ranked->count() > 1 && $ranked[0]['support'] < $ranked[1]['support'] * self::CLEAR_WINNER_RATIO) {
            return null;
        }

        $best = $ranked[0]['best'];

        return [
            'category_id' => $best['category_id'],
            'source' => 'history',
            'reason' => ($best['score'] >= 1.0 ? 'Same as your earlier "' : 'Similar to your earlier "').Str::limit($best['description'], 60).'"',
        ];
    }

    /**
     * Ask the model, offering only the owner's category names of the right type. Whatever
     * it answers is checked against those ids, anything else is discarded.
     *
     * @return array{category_id: int, source: 'ai', reason: string}|null
     */
    private function fromAi(User $user, string $description, TransactionType $type): ?array
    {
        $description = trim($description);

        if ($description === '' || ! $this->ai->isEnabled(AiFeature::Categorize)) {
            return null;
        }

        $categories = $user->categories()->where('type', $type->value)->orderBy('name')->get(['id', 'name']);

        if ($categories->isEmpty()) {
            return null;
        }

        $prompt = "Categories (id: name):\n"
            .$categories->map(fn ($category) => $category->id.': '.$category->name)->implode("\n")
            ."\n\nTransaction type: {$type->value}\nDescription: ".json_encode(Str::limit($description, 200, ''), JSON_UNESCAPED_UNICODE);

        $response = $this->ai->prompt(new CategorySuggestionAgent, $prompt, timeout: 30);

        if (! $response instanceof ArrayAccess) {
            return null;
        }

        $categoryId = $response['category_id'] ?? null;

        if (! is_numeric($categoryId) || ! $categories->contains('id', (int) $categoryId)) {
            return null;
        }

        $reason = trim(strip_tags((string) ($response['reason'] ?? '')));

        return [
            'category_id' => (int) $categoryId,
            'source' => 'ai',
            'reason' => $reason !== '' ? Str::limit($reason, 120) : 'Suggested by AI',
        ];
    }
}
