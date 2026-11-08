<?php

namespace App\Http\Controllers;

use App\Enums\StatementLineStatus;
use App\Http\Requests\StatementImportRequest;
use App\Http\Requests\StatementLineSignRequest;
use App\Http\Requests\StatementSignsRequest;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Statements\StatementImporter;
use App\Services\Statements\StatementMatcher;
use App\Support\FlashToast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Picking statement lines to import as transactions, and setting lines aside (ignored) or back.
 */
class StatementImportController extends Controller
{
    public function __construct(
        private readonly StatementImporter $importer,
        private readonly StatementMatcher $matcher,
    ) {}

    public function store(StatementImportRequest $request, Statement $statement): RedirectResponse
    {
        $user = $request->user();

        $result = $this->importer->import(
            $user,
            $statement,
            $user->accounts()->findOrFail($request->validated('account_id')),
            $request->choices(),
            $request->boolean('import_all'),
        );

        $message = $this->summary($result);

        if ($result['refused'] !== [] || $result['remaining'] > 0) {
            FlashToast::warning($message);
        } elseif ($result['imported'] > 0) {
            FlashToast::success($message);
        } else {
            FlashToast::info($message);
        }

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    public function ignore(Statement $statement, StatementLine $line): RedirectResponse
    {
        $this->authorizeLine($statement, $line);

        if ($this->moveLine($line, StatementLineStatus::Unmatched, StatementLineStatus::Ignored)) {
            FlashToast::success('Line ignored.');
        } else {
            FlashToast::error('Only a line that is not in Flusso can be ignored.');
        }

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * An ignored line goes back to the pool; the match run decides whether it is unmatched or already in the app.
     */
    public function restore(Statement $statement, StatementLine $line): RedirectResponse
    {
        $this->authorizeLine($statement, $line);

        if ($this->moveLine($line, StatementLineStatus::Ignored, StatementLineStatus::Unmatched)) {
            $this->matcher->match($statement);
            FlashToast::success('Line restored.');
        } else {
            FlashToast::error('Only an ignored line can be restored.');
        }

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * Turn money in into money out or the other way round, for a line read with the wrong sign.
     * One conditional update, only while the line is not imported or ignored; the matches are
     * worked out again because the direction of a line decides which transfers it can match.
     */
    public function flipSign(StatementLineSignRequest $request, Statement $statement, StatementLine $line): RedirectResponse
    {
        $flipped = StatementLine::query()
            ->whereKey($line->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('match_status', [StatementLineStatus::Unmatched->value, StatementLineStatus::Matched->value])
            ->where('amount', '!=', 0)
            ->update(['amount' => DB::raw('-amount'), 'sign_uncertain' => false, 'sign_reviewed' => true]);

        if ($flipped === 1) {
            $this->matcher->match($statement);
            FlashToast::success('Sign flipped.');
        } else {
            FlashToast::error('Only a line that is not imported or ignored, and not 0.00, can change sign.');
        }

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * The owner checked a line whose sign was uncertain and it is right as read.
     */
    public function confirmSign(StatementLineSignRequest $request, Statement $statement, StatementLine $line): RedirectResponse
    {
        $confirmed = StatementLine::query()
            ->whereKey($line->id)
            ->where('user_id', $request->user()->id)
            ->where('sign_uncertain', true)
            ->whereIn('match_status', [StatementLineStatus::Unmatched->value, StatementLineStatus::Matched->value])
            ->update(['sign_uncertain' => false, 'sign_reviewed' => true]);

        $confirmed === 1
            ? FlashToast::success('Sign confirmed.')
            : FlashToast::error('Only a line with an uncertain sign that is not imported or ignored can be confirmed.');

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * The owner checked every line with an uncertain sign and they are right as read.
     */
    public function confirmAllSigns(StatementSignsRequest $request, Statement $statement): RedirectResponse
    {
        $confirmed = $this->uncertainLines($statement)->update(['sign_uncertain' => false, 'sign_reviewed' => true]);

        FlashToast::success($confirmed === 1 ? '1 sign confirmed.' : "{$confirmed} signs confirmed.");

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * Every line with an uncertain sign is the other way round (for a statement that prints
     * all amounts unsigned and they are all money out). One conditional update, then matching again.
     */
    public function flipAllSigns(StatementSignsRequest $request, Statement $statement): RedirectResponse
    {
        $flipped = $this->uncertainLines($statement)
            ->where('amount', '!=', 0)
            ->update(['amount' => DB::raw('-amount'), 'sign_uncertain' => false, 'sign_reviewed' => true]);

        $this->matcher->match($statement);

        FlashToast::success($flipped === 1 ? '1 sign flipped.' : "{$flipped} signs flipped.");

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * The statement's lines with an uncertain sign that are still open (not imported or ignored).
     *
     * @return Builder<StatementLine>
     */
    private function uncertainLines(Statement $statement): Builder
    {
        return StatementLine::query()
            ->where('statement_id', $statement->id)
            ->where('user_id', $statement->user_id)
            ->where('sign_uncertain', true)
            ->whereIn('match_status', [StatementLineStatus::Unmatched->value, StatementLineStatus::Matched->value]);
    }

    /**
     * Change the status only if the line is still in the expected one (a single conditional update).
     */
    private function moveLine(StatementLine $line, StatementLineStatus $from, StatementLineStatus $to): bool
    {
        return StatementLine::query()
            ->whereKey($line->id)
            ->where('match_status', $from->value)
            ->update(['match_status' => $to->value]) === 1;
    }

    private function authorizeLine(Statement $statement, StatementLine $line): void
    {
        $this->authorize('update', $statement);

        abort_unless($line->statement_id === $statement->id, 404);
    }

    /**
     * @param  array{imported: int, already_in_app: int, refused: list<array{line_id: int, reason: string}>, remaining: int}  $result
     */
    private function summary(array $result): string
    {
        $parts = [
            "Imported {$result['imported']} ".($result['imported'] === 1 ? 'line' : 'lines').'.',
        ];

        if ($result['remaining'] > 0) {
            $parts[] = "{$result['remaining']} remain: run it again.";
        }

        if ($result['already_in_app'] > 0) {
            $parts[] = "{$result['already_in_app']} already in the app, so not imported again.";
        }

        if ($result['refused'] !== []) {
            $reasons = collect($result['refused'])->countBy('reason')->map(fn (int $count, string $reason) => "{$reason} ({$count})")->values();
            $parts[] = count($result['refused']).' refused: '.$reasons->implode(' ');
        }

        return implode(' ', $parts);
    }
}
