<?php

namespace App\Services\QuickEntry;

use App\Ai\Agents\QuickEntryAgent;
use App\Enums\AiFeature;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\CategorySuggestionService;
use App\Support\DescriptionMatcher;
use ArrayAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns free text into proposed transactions for the add-transaction form. The
 * text is read locally; the owner's history then picks the category, and only
 * when it cannot and AI is on does the model see the leftover description plus
 * the owner's category names (never accounts, balances or amounts). Nothing here writes.
 */
class QuickEntryService
{
    /** Bounds the model calls one request can cause. */
    private const MAX_AI_CALLS = 5;

    public function __construct(
        private readonly QuickEntryParser $parser,
        private readonly CategorySuggestionService $categorySuggestions,
        private readonly AiManager $ai,
    ) {}

    /**
     * @return array{entries: list<array{type: string, amount: string, account_id: int|null, category_id: int|null, description: string|null, date: string, source: 'history'|'ai'|null}>, unparsed: list<string>}
     */
    public function parse(User $user, string $text): array
    {
        $parsed = $this->parser->parse($text, $this->accountsOf($user), now((string) config('app.timezone'))->startOfDay()->toImmutable());

        $useAi = $this->ai->isEnabled(AiFeature::QuickEntry);
        $aiCalls = 0;

        /** @var array<string, Collection<int, array{normalized: string, description: string, category_id: int, count: int}>> $histories */
        $histories = [];
        /** @var array<string, EloquentCollection<int, Category>> $categories */
        $categories = [];
        /** @var array<string, int|null> $answers */
        $answers = [];
        $entries = [];

        foreach ($parsed['entries'] as $entry) {
            $categoryId = null;
            $source = null;

            if ($entry['description'] !== null) {
                $type = TransactionType::from($entry['type']);
                $histories[$type->value] ??= $this->categorySuggestions->history($user, $type);
                $match = $this->categorySuggestions->fromHistory($histories[$type->value], $entry['description']);

                if ($match !== null) {
                    $categoryId = $match['category_id'];
                    $source = 'history';
                } elseif ($useAi && DescriptionMatcher::normalize($entry['description']) !== '') {
                    $key = $type->value.'|'.DescriptionMatcher::normalize($entry['description']);

                    if (! array_key_exists($key, $answers) && $aiCalls < self::MAX_AI_CALLS) {
                        $aiCalls++;
                        $categories[$type->value] ??= $user->categories()->where('type', $type->value)->orderBy('name')->get(['id', 'name']);
                        $answers[$key] = $this->askModel($categories[$type->value], $type, $entry['description']);
                    }

                    $categoryId = $answers[$key] ?? null;
                    $source = $categoryId !== null ? 'ai' : null;
                }
            }

            $entries[] = [
                'type' => $entry['type'],
                'amount' => $entry['amount'],
                'account_id' => $entry['account_id'],
                'category_id' => $categoryId,
                'description' => $entry['description'],
                'date' => $entry['date'],
                'source' => $source,
            ];
        }

        return ['entries' => $entries, 'unparsed' => $parsed['unparsed']];
    }

    /**
     * Only the owner's own active accounts are ever matched.
     *
     * @return array<int, array{id: int, name: string, currency: string}>
     */
    private function accountsOf(User $user): array
    {
        $accounts = [];

        foreach ($user->accounts()->orderBy('id')->get(['id', 'name', 'currency']) as $account) {
            $accounts[] = ['id' => $account->id, 'name' => (string) $account->name, 'currency' => (string) $account->currency];
        }

        return $accounts;
    }

    /**
     * Ask the model for one of the given categories. Whatever it answers is checked against
     * those ids, anything else is discarded.
     *
     * @param  EloquentCollection<int, Category>  $categories
     */
    private function askModel(EloquentCollection $categories, TransactionType $type, string $description): ?int
    {
        if ($categories->isEmpty()) {
            return null;
        }

        $prompt = "Categories (id: name):\n"
            .$categories->map(fn (Category $category): string => $category->id.': '.$category->name)->implode("\n")
            ."\n\nTransaction type: {$type->value}\nNote: ".json_encode(Str::limit($description, QuickEntryParser::MAX_DESCRIPTION_LENGTH, ''), JSON_UNESCAPED_UNICODE);

        $response = $this->ai->prompt(new QuickEntryAgent, $prompt, timeout: 30);

        if (! $response instanceof ArrayAccess) {
            return null;
        }

        $categoryId = $response['category_id'] ?? null;

        return is_numeric($categoryId) && $categories->contains('id', (int) $categoryId) ? (int) $categoryId : null;
    }
}
