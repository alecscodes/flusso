<?php

namespace App\Services\Statements;

use App\Enums\StatementLineStatus;
use App\Enums\StatementParseStatus;
use App\Jobs\ParseStatementWithAi;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The statement vault: keeps the file for good, reads its text and lines, indexes
 * it for search and matches its lines against the account's transactions.
 */
class StatementService
{
    /** Hard cap per file, in kilobytes. */
    public const MAX_SIZE_KB = 20480;

    /** Longest text kept (and indexed) per statement; a normal statement is under 1 MB. */
    public const MAX_TEXT_CHARACTERS = 5_000_000;

    public function __construct(
        private readonly PdfTextExtractor $pdfText,
        private readonly StatementParser $parser,
        private readonly StatementSearchIndex $index,
        private readonly StatementMatcher $matcher,
    ) {}

    /**
     * Keep an uploaded file. The same file (by SHA-256) is never stored twice for a user.
     *
     * @param  array{institution?: string|null, account_label?: string|null, account_id?: int|string|null, period_start?: string|null, period_end?: string|null}  $meta
     * @return array{statement: Statement, created: bool}
     */
    public function store(User $user, UploadedFile $file, array $meta = []): array
    {
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());
        $existing = $user->statements()->where('sha256', $hash)->first();

        if ($existing !== null) {
            return ['statement' => $existing, 'created' => false];
        }

        $isPdf = strtolower($file->getClientOriginalExtension()) === 'pdf';
        $path = 'statements/'.$user->id.'/'.Str::uuid()->toString().($isPdf ? '.pdf' : '.csv');

        // Streamed to the disk, so a 20 MB upload is not held in memory.
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        try {
            $statement = $user->statements()->create([
                'account_id' => filled($meta['account_id'] ?? null) ? (int) $meta['account_id'] : null,
                'institution' => $this->blankToNull($meta['institution'] ?? null),
                'account_label' => $this->blankToNull($meta['account_label'] ?? null),
                'period_start' => $this->blankToNull($meta['period_start'] ?? null),
                'period_end' => $this->blankToNull($meta['period_end'] ?? null),
                'original_filename' => $this->safeFilename($file->getClientOriginalName()),
                'mime_type' => $isPdf ? 'application/pdf' : 'text/csv',
                'size' => (int) $file->getSize(),
                'sha256' => $hash,
                'path' => $path,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same file raced: keep the first.
            Storage::disk('local')->delete($path);

            return ['statement' => $user->statements()->where('sha256', $hash)->firstOrFail(), 'created' => false];
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        $this->process($statement);

        return ['statement' => $statement->refresh(), 'created' => true];
    }

    /**
     * Read the file again: text, lines (replacing the open ones), search index and matching.
     * The deterministic parsers answer here; when they find nothing and the model may be
     * asked, the statement waits for the queued ParseStatementWithAi job. False when the owner
     * decided on a line meanwhile (see replaceLines), in which case nothing was changed.
     */
    public function process(Statement $statement): bool
    {
        $disk = Storage::disk('local');

        $text = $statement->isPdf()
            ? ($this->pdfText->extract($disk->path($statement->path)) ?? '')
            : CsvReader::decode((string) $disk->get($statement->path));

        $text = (string) mb_convert_encoding(str_replace("\0", '', $text), 'UTF-8', 'UTF-8');
        $textTruncated = mb_strlen($text) > self::MAX_TEXT_CHARACTERS;
        $text = $textTruncated ? mb_substr($text, 0, self::MAX_TEXT_CHARACTERS) : $text;
        $result = $this->parser->parse($text, ! $statement->isPdf());
        $needsAi = $result->lines === [] && $text !== '' && $this->parser->canUseAi();

        $replaced = $this->replaceLines($statement, $result, [
            'extracted_text' => $text === '' ? null : $text,
            'text_truncated' => $textTruncated,
            'parse_status' => $needsAi ? StatementParseStatus::PendingAi : StatementParseStatus::Done,
        ]);

        if ($replaced && $needsAi) {
            $this->dispatchAiParse($statement);
        }

        return $replaced;
    }

    /**
     * The model-assisted read of a statement waiting for it (the queued job's work).
     * Whatever the model finds replaces the lines; nothing usable is a failed read.
     */
    public function completeWithAi(Statement $statement): void
    {
        if ($statement->parse_status !== StatementParseStatus::PendingAi) {
            return;
        }

        $result = $this->parser->parseWithAi((string) $statement->extracted_text);

        if ($result === null) {
            $this->markAiFailed($statement->id);

            return;
        }

        $this->replaceLines($statement, $result, [
            'parse_status' => $result->partial ? StatementParseStatus::AiPartial : StatementParseStatus::Done,
        ]);
    }

    /**
     * Mark a statement that is still waiting for the model as unreadable by it.
     */
    public function markAiFailed(int $statementId): void
    {
        Statement::query()
            ->whereKey($statementId)
            ->where('parse_status', StatementParseStatus::PendingAi->value)
            ->update(['parse_status' => StatementParseStatus::AiFailed->value]);
    }

    /**
     * Parse again (for example after turning AI on). Refused once a line was imported or
     * ignored, or had its sign checked, because re-reading would lose that decision.
     */
    public function reprocess(Statement $statement): bool
    {
        if ($this->hasDecidedLines($statement)) {
            return false;
        }

        return $this->process($statement);
    }

    /**
     * A line the owner imported, ignored or checked the sign of: re-reading the file would lose it.
     */
    private function hasDecidedLines(Statement $statement): bool
    {
        return $statement->lines()
            ->where(fn ($query) => $query
                ->whereIn('match_status', [StatementLineStatus::Imported->value, StatementLineStatus::Ignored->value])
                ->orWhere('sign_reviewed', true))
            ->exists();
    }

    /**
     * Swap the statement's lines for the parse result, then update its search index and matches.
     * The check for decided lines, the delete and the insert are one transaction on the locked
     * statement row, so a concurrent import cannot be lost; false (nothing changed) when there is one.
     *
     * @param  array<string, mixed>  $attributes  what else to save on the statement
     */
    private function replaceLines(Statement $statement, ParseResult $result, array $attributes): bool
    {
        $replaced = DB::transaction(function () use ($statement, $result, $attributes) {
            Statement::query()->whereKey($statement->id)->lockForUpdate()->value('id');

            if ($this->hasDecidedLines($statement)) {
                return false;
            }

            // The index rows of the old lines are found through the lines, so forget first.
            $this->index->forget($statement);
            $statement->lines()->delete();

            $now = now();

            foreach (array_chunk($result->lines, 200) as $chunk) {
                StatementLine::query()->insert(array_map(fn (ParsedLine $line) => [
                    'statement_id' => $statement->id,
                    'user_id' => $statement->user_id,
                    'posted_on' => $line->date,
                    'amount' => $line->amount,
                    'currency' => $line->currency,
                    'description' => $line->description,
                    'raw_line' => mb_substr($line->raw, 0, 1000),
                    'parsed_by' => $line->parsedBy,
                    'sign_uncertain' => $line->signUncertain,
                    'match_status' => StatementLineStatus::Unmatched->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $statement->forceFill([
                ...$attributes,
                'parser' => $result->parser,
                'lines_truncated' => $result->truncated,
                'date_order_assumed' => $result->dateOrderAssumed,
                'parsed_at' => $now,
                'institution' => $statement->institution ?? $result->institution,
                'period_start' => $statement->period_start ?? $result->periodStart(),
                'period_end' => $statement->period_end ?? $result->periodEnd(),
            ])->save();

            return true;
        });

        if (! $replaced) {
            return false;
        }

        $statement->refresh();

        $this->index->index($statement);
        $this->matcher->match($statement);

        return true;
    }

    /**
     * The upload must not fail because the queue is down: the statement is kept and shows the failure.
     */
    private function dispatchAiParse(Statement $statement): void
    {
        try {
            ParseStatementWithAi::dispatch($statement->id);
        } catch (Throwable $exception) {
            report($exception);

            $this->markAiFailed($statement->id);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return mb_substr($name !== '' ? $name : 'statement', 0, 250);
    }
}
