<?php

namespace App\Services\Statements;

use App\Enums\StatementLineStatus;
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

    private const MAX_TEXT_CHARACTERS = 3000000;

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
     */
    public function process(Statement $statement, int $aiChunks = AiLineParser::MAX_CHUNKS): void
    {
        $disk = Storage::disk('local');

        $text = $statement->isPdf()
            ? ($this->pdfText->extract($disk->path($statement->path)) ?? '')
            : CsvReader::decode((string) $disk->get($statement->path));

        $text = mb_substr((string) mb_convert_encoding(str_replace("\0", '', $text), 'UTF-8', 'UTF-8'), 0, self::MAX_TEXT_CHARACTERS);
        $result = $this->parser->parse($text, ! $statement->isPdf(), $aiChunks);

        // The index rows of the old lines are found through the lines, so forget first.
        $this->index->forget($statement);

        DB::transaction(function () use ($statement, $text, $result) {
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
                    'match_status' => StatementLineStatus::Unmatched->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $statement->forceFill([
                'extracted_text' => $text === '' ? null : $text,
                'parser' => $result->parser,
                'parsed_at' => $now,
                'institution' => $statement->institution ?? $result->institution,
                'period_start' => $statement->period_start ?? $result->periodStart(),
                'period_end' => $statement->period_end ?? $result->periodEnd(),
            ])->save();
        });

        $statement->refresh();

        $this->index->index($statement);
        $this->matcher->match($statement);
    }

    /**
     * Parse again (for example after turning AI on). Refused once a line was imported or
     * ignored, because re-reading would lose that decision.
     */
    public function reprocess(Statement $statement): bool
    {
        $decided = $statement->lines()
            ->whereIn('match_status', [StatementLineStatus::Imported->value, StatementLineStatus::Ignored->value])
            ->exists();

        if ($decided) {
            return false;
        }

        $this->process($statement);

        return true;
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
