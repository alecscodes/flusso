<?php

namespace App\Jobs;

use App\Models\Statement;
use App\Services\Statements\StatementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The model-assisted read of a statement the deterministic parsers could not read.
 * It can take minutes (one model call per chunk of text), so it never runs in the
 * upload request. One try only: a failed read shows as "AI could not read this
 * file" and the owner decides whether to read it again.
 */
class ParseStatementWithAi implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Longer than the timeout, so the lock outlives a job that is still running. */
    public int $uniqueFor = 700;

    public function __construct(public readonly int $statementId) {}

    public function uniqueId(): string
    {
        return (string) $this->statementId;
    }

    public function handle(StatementService $statements): void
    {
        $statement = Statement::query()->find($this->statementId);

        if ($statement !== null) {
            $statements->completeWithAi($statement);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(StatementService::class)->markAiFailed($this->statementId);

        // Only the class: the message can echo the statement text sent to the model.
        Log::warning('Reading a statement with AI failed.', [
            'statement_id' => $this->statementId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
