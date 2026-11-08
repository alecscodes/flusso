<?php

namespace App\Services\Statements;

use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One search over every statement of a user: the full text of each file and the
 * description of each parsed line, narrowed by date range, institution, account
 * and amount. Uses the FTS5 index when it exists and LIKE otherwise.
 *
 * @phpstan-type Segment array{text: string, hit: bool}
 * @phpstan-type Filters array{q?: string|null, date_from?: string|null, date_to?: string|null, institution?: string|null, account_id?: int|string|null, amount_min?: string|float|int|null, amount_max?: string|float|int|null}
 */
class StatementSearch
{
    public const MAX_STATEMENTS = 50;

    public const MAX_LINES_PER_STATEMENT = 6;

    private const MAX_TERMS = 8;

    /** The window of text shown around the first hit when FTS5 is not available. */
    private const SNIPPET_BEFORE = 80;

    private const SNIPPET_LENGTH = 240;

    public function __construct(private readonly StatementSearchIndex $index) {}

    /**
     * Whether the filters ask for anything at all (an empty search lists nothing).
     *
     * @param  Filters  $filters
     */
    public function hasCriteria(array $filters): bool
    {
        foreach (['q', 'date_from', 'date_to', 'institution', 'account_id', 'amount_min', 'amount_max'] as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Filters  $filters
     * @return array{results: list<array<string, mixed>>, truncated: bool}
     */
    public function search(User $user, array $filters): array
    {
        if (! $this->hasCriteria($filters)) {
            return ['results' => [], 'truncated' => false];
        }

        $terms = self::terms((string) ($filters['q'] ?? ''));
        $hasAmount = is_numeric($filters['amount_min'] ?? null) || is_numeric($filters['amount_max'] ?? null);

        // Only punctuation was typed: nothing can match.
        if (filled($filters['q'] ?? null) && $terms === []) {
            return ['results' => [], 'truncated' => false];
        }

        $textIds = [];
        $lineIds = null;
        $capped = false;

        if ($terms !== []) {
            $matches = $this->matchingIds($user, $terms);
            $textIds = $matches['statements'];
            $lineIds = $matches['lines'];
            $capped = count($textIds) >= StatementSearchIndex::MAX_IDS || count($lineIds) >= StatementSearchIndex::MAX_IDS;
        }

        $counts = ($terms !== [] || $hasAmount) ? $this->lineCounts($user, $filters, $lineIds) : [];

        $query = $this->statementQuery($user, $filters);

        if ($terms !== [] || $hasAmount) {
            $ids = $hasAmount ? [] : $textIds;
            $ids = array_values(array_unique([...$ids, ...array_keys($counts)]));

            if ($ids === []) {
                return ['results' => [], 'truncated' => false];
            }

            $query->whereIn('id', $ids);
        }

        $statements = $query
            ->with('account:id,name')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(self::MAX_STATEMENTS + 1)
            ->get();

        $truncated = $statements->count() > self::MAX_STATEMENTS || $capped;
        $textMatched = array_flip($hasAmount ? [] : $textIds);
        $results = [];

        foreach ($statements->take(self::MAX_STATEMENTS) as $statement) {
            $lines = isset($counts[$statement->id]) ? $this->topLines($user, $filters, $lineIds, $statement->id) : [];

            $results[] = [
                'id' => $statement->id,
                'original_filename' => $statement->original_filename,
                'institution' => $statement->institution,
                'account_label' => $statement->account_label,
                'account_name' => $statement->account?->name,
                'period_start' => $statement->period_start?->toDateString(),
                'period_end' => $statement->period_end?->toDateString(),
                'snippet' => isset($textMatched[$statement->id]) ? $this->snippetFor($statement, $terms) : null,
                'lines_total' => $counts[$statement->id] ?? 0,
                'lines' => array_map(fn (StatementLine $line) => [
                    'id' => $line->id,
                    'posted_on' => $line->posted_on->toDateString(),
                    'amount' => $line->amount,
                    'currency' => $line->currency,
                    'description' => self::highlight($line->description, $terms),
                    'match_status' => $line->match_status->value,
                ], $lines),
            ];
        }

        return ['results' => $results, 'truncated' => $truncated];
    }

    /**
     * The words of a search box, at most a few, with anything that has no letter or digit dropped.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1);

        return array_slice(array_values($words), 0, self::MAX_TERMS);
    }

    /**
     * The text split into plain and matching segments, so it can be shown highlighted without HTML.
     *
     * @param  list<string>  $terms
     * @return list<Segment>
     */
    public static function highlight(string $text, array $terms): array
    {
        $words = array_values(array_filter(array_map(fn (string $term) => trim($term, '*'), $terms), fn (string $term) => $term !== ''));

        if ($words === [] || $text === '') {
            return $text === '' ? [] : [['text' => $text, 'hit' => false]];
        }

        $pattern = '/('.implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words)).')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [['text' => $text, 'hit' => false]];
        }

        $lowered = array_map(mb_strtolower(...), $words);

        return array_map(fn (string $part) => ['text' => $part, 'hit' => in_array(mb_strtolower($part), $lowered, true)], $parts);
    }

    /**
     * A short highlighted excerpt of the text around the first matching term.
     *
     * @param  list<string>  $terms
     * @return list<Segment>
     */
    public static function snippet(string $text, array $terms, int $radius = 70): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $position = null;
        $length = 0;

        foreach ($terms as $term) {
            $found = mb_stripos($text, $term);

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
                $length = mb_strlen($term);
            }
        }

        $start = max(0, ($position ?? 0) - $radius);
        $excerpt = mb_substr($text, $start, $radius * 2 + $length);

        return self::highlight(($start > 0 ? '…' : '').$excerpt.($start + mb_strlen($excerpt) < mb_strlen($text) ? '…' : ''), $terms);
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Statement>
     */
    private function statementQuery(User $user, array $filters): Builder
    {
        $institution = $filters['institution'] ?? null;

        return Statement::query()
            ->select([
                'id', 'user_id', 'account_id', 'institution', 'account_label', 'period_start', 'period_end',
                'original_filename', 'mime_type', 'size', 'parser', 'parsed_at', 'created_at', 'updated_at',
            ])
            ->where('user_id', $user->id)
            ->when(filled($filters['account_id'] ?? null), fn (Builder $query) => $query->where('account_id', (int) $filters['account_id']))
            ->when(is_string($institution) && $institution !== '', fn (Builder $query) => $query->whereRaw("institution like ? escape '\\'", ['%'.self::escapeLike((string) $institution).'%']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('period_end')->orWhere('period_end', '>=', $filters['date_from'])))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('period_start')->orWhere('period_start', '<=', $filters['date_to'])));
    }

    /**
     * How many lines match per statement, counted in SQL so no cap hides a statement or its total.
     *
     * @param  Filters  $filters
     * @param  list<int>|null  $lineIds
     * @return array<int, int> line count by statement id
     */
    private function lineCounts(User $user, array $filters, ?array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        $counts = [];

        $rows = $this->lineQuery($user, $filters, $lineIds)
            ->selectRaw('statement_id, count(*) as total')
            ->groupBy('statement_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->statement_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The newest few matching lines of one statement.
     *
     * @param  Filters  $filters
     * @param  list<int>|null  $lineIds
     * @return list<StatementLine>
     */
    private function topLines(User $user, array $filters, ?array $lineIds, int $statementId): array
    {
        return $this->lineQuery($user, $filters, $lineIds)
            ->where('statement_id', $statementId)
            ->orderByDesc('posted_on')
            ->orderBy('id')
            ->limit(self::MAX_LINES_PER_STATEMENT)
            ->get()
            ->values()
            ->all();
    }

    /**
     * The lines matching the search words (when given), the amount and the date range.
     *
     * @param  Filters  $filters
     * @param  list<int>|null  $lineIds
     * @return Builder<StatementLine>
     */
    private function lineQuery(User $user, array $filters, ?array $lineIds): Builder
    {
        return StatementLine::query()
            ->where('user_id', $user->id)
            ->whereIn('statement_id', $this->statementQuery($user, $filters)->select('id'))
            ->when($lineIds !== null, fn (Builder $query) => $query->whereIn('id', $lineIds ?? []))
            ->when(is_numeric($filters['amount_min'] ?? null), fn (Builder $query) => $query->whereRaw('abs(amount) >= cast(? as real)', [(float) $filters['amount_min']]))
            ->when(is_numeric($filters['amount_max'] ?? null), fn (Builder $query) => $query->whereRaw('abs(amount) <= cast(? as real)', [(float) $filters['amount_max']]))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query) => $query->where('posted_on', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query) => $query->where('posted_on', '<=', $filters['date_to']));
    }

    /**
     * Ids of the statements (by text) and lines matching every term: FTS5 when
     * the index exists and answers, LIKE otherwise.
     *
     * @param  list<string>  $terms
     * @return array{statements: list<int>, lines: list<int>}
     */
    private function matchingIds(User $user, array $terms): array
    {
        if ($this->index->isAvailable()) {
            try {
                return $this->index->match($user->id, $terms);
            } catch (Throwable) {
                // Fall back to LIKE below.
            }
        }

        $statements = Statement::query()->where('user_id', $user->id);
        $lines = StatementLine::query()->where('user_id', $user->id);

        foreach ($terms as $term) {
            $pattern = '%'.self::escapeLike($term).'%';
            $statements->whereRaw("extracted_text like ? escape '\\'", [$pattern]);
            $lines->whereRaw("description like ? escape '\\'", [$pattern]);
        }

        return [
            'statements' => $statements->limit(StatementSearchIndex::MAX_IDS)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'lines' => $lines->limit(StatementSearchIndex::MAX_IDS)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return list<Segment>
     */
    private function snippetFor(Statement $statement, array $terms): array
    {
        if ($this->index->isAvailable()) {
            try {
                $segments = $this->index->snippet($statement->id, $terms);

                if ($segments !== null) {
                    return $segments;
                }
            } catch (Throwable) {
                // Fall back to LIKE below.
            }
        }

        return $this->likeSnippet($statement->id, $terms);
    }

    /**
     * The excerpt without FTS5: the database finds the first hit and cuts a window around it,
     * so the full text (up to millions of characters) never reaches PHP.
     *
     * @param  list<string>  $terms
     * @return list<Segment>
     */
    private function likeSnippet(int $statementId, array $terms): array
    {
        $columns = [];
        $bindings = [];

        foreach ($terms as $index => $term) {
            $columns[] = "instr(lower(extracted_text), ?) as hit_{$index}";
            $bindings[] = strtolower($term);
        }

        $row = DB::selectOne('select '.implode(', ', $columns).' from statements where id = ?', [...$bindings, $statementId]);

        if ($row === null) {
            return [];
        }

        $positions = array_filter(array_map(fn (mixed $position) => (int) $position, (array) $row), fn (int $position) => $position > 0);
        $start = max(1, ($positions === [] ? 1 : min($positions)) - self::SNIPPET_BEFORE);

        $excerpt = (string) DB::scalar('select substr(extracted_text, ?, ?) from statements where id = ?', [$start, self::SNIPPET_LENGTH, $statementId]);
        $excerpt = trim((string) preg_replace('/\s+/u', ' ', $excerpt));

        if ($excerpt === '') {
            return [];
        }

        return self::highlight(($start > 1 ? '…' : '').$excerpt.(mb_strlen($excerpt) >= self::SNIPPET_LENGTH - 10 ? '…' : ''), $terms);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
