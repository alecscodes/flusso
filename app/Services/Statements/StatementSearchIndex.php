<?php

namespace App\Services\Statements;

use App\Models\Statement;
use App\Models\StatementLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SQLite FTS5 index over statement text and line descriptions, kept in sync
 * by explicit calls (no triggers). A statement's own row has the rowid
 * -statement_id and a line's row has its id, so rows are found without scanning.
 * Where FTS5 is not available, isAvailable() is false and search uses LIKE.
 */
class StatementSearchIndex
{
    private const TABLE = 'statement_search';

    /** Most rows one search reads back; when a search reaches it, its results are flagged as truncated. */
    public const MAX_IDS = 5000;

    public function isAvailable(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite' && Schema::hasTable(self::TABLE);
    }

    /**
     * (Re)index a statement: its full text and every one of its lines.
     */
    public function index(Statement $statement): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        DB::transaction(function () use ($statement) {
            $this->forget($statement);

            $text = (string) $statement->extracted_text;

            if ($text !== '') {
                $this->insert(-$statement->id, $text, $statement->id, 0, $statement->user_id);
            }

            $statement->lines()->select(['id', 'description'])->orderBy('id')->each(function (StatementLine $line) use ($statement) {
                $this->insert($line->id, (string) $line->description, $statement->id, $line->id, $statement->user_id);
            }, 500);
        });
    }

    /**
     * Drop the statement's rows. Call it while its lines still exist.
     */
    public function forget(Statement $statement): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        DB::delete('DELETE FROM '.self::TABLE.' WHERE rowid = ?', [-$statement->id]);
        DB::delete('DELETE FROM '.self::TABLE.' WHERE rowid IN (SELECT id FROM statement_lines WHERE statement_id = ?)', [$statement->id]);
    }

    /**
     * The statements (by their own text) and lines of the user matching every term.
     *
     * @param  list<string>  $terms
     * @return array{statements: list<int>, lines: list<int>}
     */
    public function match(int $userId, array $terms): array
    {
        $rows = DB::select(
            'SELECT statement_id, line_id FROM '.self::TABLE.' WHERE '.self::TABLE.' MATCH ? AND user_id = ? LIMIT '.self::MAX_IDS,
            [self::matchExpression($terms), $userId],
        );

        $statements = [];
        $lines = [];

        foreach ($rows as $row) {
            if ((int) $row->line_id === 0) {
                $statements[] = (int) $row->statement_id;
            } else {
                $lines[] = (int) $row->line_id;
            }
        }

        return ['statements' => $statements, 'lines' => $lines];
    }

    /**
     * A short excerpt of the statement's own text around the matching words, made by FTS5 so the
     * full text is never loaded. Null when the statement's text does not match.
     *
     * @param  list<string>  $terms
     * @return list<array{text: string, hit: bool}>|null
     */
    public function snippet(int $statementId, array $terms): ?array
    {
        $rows = DB::select(
            'SELECT snippet('.self::TABLE.', 0, ?, ?, ?, 24) AS excerpt FROM '.self::TABLE.' WHERE '.self::TABLE.' MATCH ? AND rowid = ?',
            ["\x02", "\x03", '…', self::matchExpression($terms), -$statementId],
        );

        if ($rows === []) {
            return null;
        }

        $parts = preg_split('/\x02(.*?)\x03/su', (string) $rows[0]->excerpt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $segments = [];

        foreach ($parts as $index => $part) {
            $text = (string) preg_replace('/\s+/u', ' ', $part);

            if ($text !== '') {
                $segments[] = ['text' => $text, 'hit' => $index % 2 === 1];
            }
        }

        return $segments;
    }

    /**
     * Every term as a quoted FTS5 prefix phrase, so operators, quotes and other
     * punctuation typed by the user can never become query syntax.
     *
     * @param  list<string>  $terms
     */
    public static function matchExpression(array $terms): string
    {
        return implode(' ', array_map(fn (string $term) => '"'.str_replace('"', '""', $term).'"*', $terms));
    }

    private function insert(int $rowId, string $text, int $statementId, int $lineId, int $userId): void
    {
        DB::insert(
            'INSERT INTO '.self::TABLE.' (rowid, text, statement_id, line_id, user_id) VALUES (?, ?, ?, ?, ?)',
            [$rowId, $text, $statementId, $lineId, $userId],
        );
    }
}
