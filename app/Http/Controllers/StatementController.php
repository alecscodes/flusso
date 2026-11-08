<?php

namespace App\Http\Controllers;

use App\Enums\AiFeature;
use App\Enums\StatementLineStatus;
use App\Enums\StatementParseStatus;
use App\Http\Requests\StatementUpdateRequest;
use App\Http\Requests\StatementUploadRequest;
use App\Models\Category;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Ai\AiManager;
use App\Services\Statements\StatementImporter;
use App\Services\Statements\StatementMatcher;
use App\Services\Statements\StatementSearch;
use App\Services\Statements\StatementService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StatementController extends Controller
{
    private const LINES_PER_PAGE = 100;

    private const CANDIDATES_PER_LINE = 3;

    public function __construct(
        private readonly StatementService $statementService,
        private readonly StatementSearch $search,
        private readonly StatementMatcher $matcher,
        private readonly AccountService $accountService,
        private readonly AiManager $ai,
        private readonly StatementImporter $importer,
    ) {}

    /**
     * The vault: every statement, newest period first, and the search over all of them.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Statement::class);

        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'institution' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'integer'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        $statements = $user->statements()
            ->select(['id', 'user_id', 'account_id', 'institution', 'account_label', 'period_start', 'period_end', 'original_filename', 'mime_type', 'size', 'parser', 'lines_truncated', 'text_truncated', 'date_order_assumed', 'parse_status', 'created_at'])
            ->with('account:id,name')
            ->withCount([
                'lines',
                'lines as matched_lines_count' => fn ($query) => $query->where('match_status', StatementLineStatus::Matched->value),
                'lines as unmatched_lines_count' => fn ($query) => $query->where('match_status', StatementLineStatus::Unmatched->value),
            ])
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Statement $statement) => $this->summary($statement));

        return Inertia::render('Statements/Index', [
            'statements' => $statements,
            'search' => $this->search->hasCriteria($filters) ? $this->search->search($user, $filters) : null,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'institution' => $filters['institution'] ?? '',
                'account_id' => isset($filters['account_id']) ? (string) $filters['account_id'] : '',
                'amount_min' => isset($filters['amount_min']) ? (string) $filters['amount_min'] : '',
                'amount_max' => isset($filters['amount_max']) ? (string) $filters['amount_max'] : '',
            ],
            'accounts' => $this->accountOptions($user),
            'institutions' => $user->statements()->whereNotNull('institution')->distinct()->orderBy('institution')->pluck('institution'),
            'aiEnabled' => $this->ai->isEnabled(AiFeature::Statements),
            'maxUploadMb' => intdiv(StatementService::MAX_SIZE_KB, 1024),
        ]);
    }

    /**
     * Keep one or more files. A file that is already in the vault is skipped, not duplicated.
     */
    public function store(StatementUploadRequest $request): RedirectResponse
    {
        $user = $request->user();

        /** @var array{institution?: string|null, account_label?: string|null, account_id?: int|string|null, period_start?: string|null, period_end?: string|null} $details */
        $details = $request->safe()->only(['institution', 'account_label', 'account_id', 'period_start', 'period_end']);

        $added = [];
        $duplicates = [];

        foreach (Arr::wrap($request->file('files')) as $file) {
            $result = $this->statementService->store($user, $file, $details);

            if ($result['created']) {
                $added[] = $result['statement'];
            } else {
                $duplicates[] = $result['statement']->original_filename;
            }
        }

        if ($added === []) {
            throw ValidationException::withMessages([
                'files' => count($duplicates) === 1
                    ? 'This file is already in your vault.'
                    : 'These files are already in your vault.',
            ]);
        }

        FlashToast::success(count($added) === 1
            ? 'Statement added to your vault.'
            : count($added).' statements added to your vault.');

        if (collect($added)->contains(fn (Statement $statement) => $statement->parse_status === StatementParseStatus::PendingAi)) {
            FlashToast::info('The AI is reading what could not be read directly. This can take a few minutes.');
        }

        if ($duplicates !== []) {
            FlashToast::warning('Skipped, already in your vault: '.implode(', ', $duplicates));
        }

        return count($added) === 1 && $duplicates === []
            ? redirect()->route('statements.show', $added[0])
            : redirect()->route('statements.index');
    }

    /**
     * One statement: its lines with their match status, and what to check in the app.
     */
    public function show(Request $request, Statement $statement): Response
    {
        $this->authorize('view', $statement);

        $filter = $request->query('status');
        $status = is_string($filter) ? StatementLineStatus::tryFrom($filter) : null;

        $lines = $statement->lines()
            ->with('matchedTransaction')
            ->when($status !== null, fn ($query) => $query->where('match_status', $status?->value))
            ->orderBy('posted_on')
            ->orderBy('id')
            ->paginate(self::LINES_PER_PAGE)
            ->withQueryString();

        $pageIds = $lines->getCollection()->pluck('id')->all();
        $candidates = $this->matcher->candidates($statement, $pageIds);
        $suggestedCategories = $this->importer->suggestCategories(
            $request->user(),
            $lines->getCollection()->filter(fn (StatementLine $line) => $line->match_status === StatementLineStatus::Unmatched),
        );
        $statement->load('account:id,name,currency');

        // One grouped query for every count the page shows.
        $groups = $statement->lines()
            ->selectRaw('match_status, count(*) as total, sum(case when sign_uncertain = 1 then 1 else 0 end) as uncertain, sum(case when sign_reviewed = 1 then 1 else 0 end) as reviewed')
            ->groupBy('match_status')
            ->toBase()
            ->get()
            ->keyBy('match_status');

        $counts = $groups->map(fn (object $group) => (int) $group->total);
        $openStatuses = [StatementLineStatus::Unmatched->value, StatementLineStatus::Matched->value];

        $statement->setAttribute('lines_count', (int) $counts->sum());
        $statement->setAttribute('matched_lines_count', $counts[StatementLineStatus::Matched->value] ?? 0);
        $statement->setAttribute('unmatched_lines_count', $counts[StatementLineStatus::Unmatched->value] ?? 0);

        return Inertia::render('Statements/Show', [
            'statement' => [
                ...$this->summary($statement),
                'currency' => $statement->account?->currency,
                'parsed_at' => $statement->parsed_at?->toIso8601String(),
                'has_text' => filled($statement->extracted_text),
                'counts' => collect(StatementLineStatus::cases())->mapWithKeys(fn (StatementLineStatus $case) => [$case->value => (int) ($counts[$case->value] ?? 0)])->all(),
                'unmatched_totals' => $this->unmatchedTotals($statement),
                'sign_uncertain_count' => (int) $groups->only($openStatuses)->sum(fn (object $group) => (int) $group->uncertain),
                'can_reparse' => (int) $groups->sum(fn (object $group) => (int) $group->reviewed) === 0 && ($counts[StatementLineStatus::Imported->value] ?? 0) + ($counts[StatementLineStatus::Ignored->value] ?? 0) === 0,
            ],
            'lines' => $lines->through(fn (StatementLine $line) => [
                'id' => $line->id,
                'posted_on' => $line->posted_on->toDateString(),
                'amount' => $line->amount,
                'currency' => $line->currency,
                'description' => $line->description,
                'parsed_by' => $line->parsed_by,
                'sign_uncertain' => $line->sign_uncertain,
                'match_status' => $line->match_status->value,
                'matched_transaction' => $line->matchedTransaction ? $this->transactionSummary($line->matchedTransaction) : null,
                'suggested_category_id' => $suggestedCategories[$line->id] ?? null,
            ]),
            'candidates' => (object) collect($candidates)
                ->only($pageIds)
                ->map(fn (array $transactions) => array_map($this->transactionSummary(...), array_slice($transactions, 0, self::CANDIDATES_PER_LINE)))
                ->all(),
            'status' => $status?->value,
            'accounts' => $this->accountOptions($request->user()),
            'categories' => $request->user()->categories()->orderBy('name')->get(['id', 'name', 'type'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name, 'type' => $category->type->value])
                ->all(),
        ]);
    }

    /**
     * Change the institution, account or period and match the lines again.
     */
    public function update(StatementUpdateRequest $request, Statement $statement): RedirectResponse
    {
        $statement->update($request->safe()->only(['institution', 'account_label', 'account_id', 'period_start', 'period_end']));

        $this->matcher->match($statement->refresh());

        FlashToast::success('Statement updated.');

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    /**
     * Read the file again, for instance after turning AI on.
     */
    public function reparse(Statement $statement): RedirectResponse
    {
        $this->authorize('update', $statement);

        if ($this->statementService->reprocess($statement)) {
            FlashToast::success($statement->parse_status === StatementParseStatus::PendingAi
                ? 'Statement read again. The AI is reading it, which can take a few minutes.'
                : 'Statement read again.');
        } else {
            FlashToast::error('Some lines were already imported, ignored or had their sign checked, so the statement is not read again.');
        }

        return redirect()->back(fallback: route('statements.show', $statement));
    }

    public function destroy(Statement $statement): RedirectResponse
    {
        $this->authorize('delete', $statement);

        $statement->delete();

        FlashToast::success('Statement deleted.');

        return redirect()->route('statements.index');
    }

    public function download(Statement $statement): BinaryFileResponse
    {
        $this->authorize('view', $statement);

        abort_if(! Storage::disk('local')->exists($statement->path), 404);

        return response()->download(
            Storage::disk('local')->path($statement->path),
            $statement->original_filename
        );
    }

    /**
     * What "import all unmatched" would add, per currency, so the owner sees the size of the action.
     *
     * @return list<array{currency: string|null, count: int, income: float, expense: float}>
     */
    private function unmatchedTotals(Statement $statement): array
    {
        $totals = [];

        $rows = $statement->lines()
            ->where('match_status', StatementLineStatus::Unmatched->value)
            ->selectRaw('currency, count(*) as total, sum(case when amount > 0 then amount else 0 end) as income, sum(case when amount < 0 then -amount else 0 end) as expense')
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $totals[] = [
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'count' => (int) $row->total,
                'income' => round((float) $row->income, 2),
                'expense' => round((float) $row->expense, 2),
            ];
        }

        return $totals;
    }

    /**
     * @return list<array{id: int, name: string, currency: string}>
     */
    private function accountOptions(User $user): array
    {
        $options = [];

        foreach ($this->accountService->getAccountsForUser($user) as $account) {
            $options[] = ['id' => (int) $account->id, 'name' => (string) $account->name, 'currency' => (string) $account->currency];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Statement $statement): array
    {
        return [
            'id' => $statement->id,
            'original_filename' => $statement->original_filename,
            'institution' => $statement->institution,
            'account_id' => $statement->account_id,
            'account_label' => $statement->account_label,
            'account_name' => $statement->account?->name,
            'period_start' => $statement->period_start?->toDateString(),
            'period_end' => $statement->period_end?->toDateString(),
            'mime_type' => $statement->mime_type,
            'size' => $statement->size,
            'parser' => $statement->parser,
            'lines_truncated' => (bool) $statement->lines_truncated,
            'text_truncated' => (bool) $statement->text_truncated,
            'date_order_assumed' => $statement->date_order_assumed,
            'parse_status' => $statement->parse_status->value,
            'lines_count' => (int) $statement->lines_count,
            'matched_lines_count' => (int) $statement->matched_lines_count,
            'unmatched_lines_count' => (int) $statement->unmatched_lines_count,
            'created_at' => $statement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionSummary(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'date' => $transaction->date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'type' => $transaction->type->value,
        ];
    }
}
