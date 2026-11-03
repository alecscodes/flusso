<?php

use App\Enums\CategoryType;
use App\Enums\StatementLineStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statements\StatementMatcher;
use App\Services\TransactionService;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: User, 1: Account, 2: Statement}
 */
function statementImportSetup(string $currency = 'EUR', bool $linked = true): array
{
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => $currency, 'balance' => 1000]);
    $statement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $linked ? $account->id : null]);

    return [$user, $account, $statement];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function statementImportLine(Statement $statement, float $amount, array $attributes = []): StatementLine
{
    return StatementLine::factory()->create([
        'statement_id' => $statement->id,
        'user_id' => $statement->user_id,
        'posted_on' => '2024-03-10',
        'amount' => $amount,
        'currency' => 'EUR',
        'description' => 'Some line',
        ...$attributes,
    ]);
}

function statementImportCategory(User $user, CategoryType $type = CategoryType::Expense, string $name = 'Food'): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'type' => $type, 'name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function statementImportTransaction(Account $account, string $date, float $amount, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'date' => $date,
        'amount' => $amount,
        'type' => TransactionType::Expense,
        'description' => null,
        'category_id' => null,
        ...$attributes,
    ]);
}

/**
 * @param  list<StatementLine>  $lines
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function statementImportPayload(array $lines, array $extra = []): array
{
    return [
        'lines' => array_map(fn (StatementLine $line) => ['id' => $line->id], $lines),
        ...$extra,
    ];
}

test('the sign decides the type and the amount is copied exactly, moving the balance through the ledger', function () {
    [$user, $account, $statement] = statementImportSetup();
    $out = statementImportLine($statement, -13.70, ['posted_on' => '2024-03-02', 'description' => 'Coffee']);
    $in = statementImportLine($statement, 1500.25, ['posted_on' => '2024-03-03', 'description' => 'Salary']);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$out, $in]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 2 lines.');

    $expense = Transaction::query()->where('description', 'Coffee')->firstOrFail();
    $income = Transaction::query()->where('description', 'Salary')->firstOrFail();

    expect($expense->type)->toBe(TransactionType::Expense)
        ->and($expense->amount)->toBe('13.70')
        ->and($expense->date->toDateString())->toBe('2024-03-02')
        ->and($expense->account_id)->toBe($account->id)
        ->and($income->type)->toBe(TransactionType::Income)
        ->and($income->amount)->toBe('1500.25')
        ->and($out->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and($out->fresh()->matched_transaction_id)->toBe($expense->id)
        ->and($in->fresh()->matched_transaction_id)->toBe($income->id)
        ->and($account->fresh()->balance)->toBe('2486.55');
});

test('the owner can change the description and pick a category', function () {
    [$user, , $statement] = statementImportSetup();
    $category = statementImportCategory($user);
    $edited = statementImportLine($statement, -8.00, ['description' => 'CARD PAYMENT 0042']);
    $untouched = statementImportLine($statement, -9.00, ['description' => 'Original text']);

    actingAs($user)->post(route('statements.import-lines', $statement), [
        'lines' => [
            ['id' => $edited->id, 'description' => 'Lunch', 'category_id' => $category->id],
            ['id' => $untouched->id, 'description' => '', 'category_id' => null],
        ],
    ])->assertSessionHasNoErrors();

    $lunch = Transaction::query()->where('description', 'Lunch')->firstOrFail();

    expect($lunch->category_id)->toBe($category->id)
        ->and(Transaction::query()->where('description', 'Original text')->firstOrFail()->category_id)->toBeNull()
        ->and($edited->fresh()->description)->toBe('CARD PAYMENT 0042');
});

test('a line already in the app is linked at the last moment and not duplicated', function () {
    [$user, $account, $statement] = statementImportSetup();
    $existing = statementImportTransaction($account, '2024-03-11', 12.50);
    $line = statementImportLine($statement, -12.50);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$line]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 0 lines. 1 already in the app, so not imported again.');

    expect(Transaction::query()->count())->toBe(1)
        ->and($line->fresh()->match_status)->toBe(StatementLineStatus::Matched)
        ->and($line->fresh()->matched_transaction_id)->toBe($existing->id);
});

test('two genuinely identical lines both become transactions, even in separate imports', function () {
    [$user, , $statement] = statementImportSetup();
    $first = statementImportLine($statement, -3.50, ['description' => 'Coffee']);
    $second = statementImportLine($statement, -3.50, ['description' => 'Coffee']);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$first]));
    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$second]));

    expect(Transaction::query()->count())->toBe(2)
        ->and($second->fresh()->match_status)->toBe(StatementLineStatus::Imported);
});

test('importing the same line twice never creates two transactions', function () {
    [$user, $account, $statement] = statementImportSetup();
    $line = statementImportLine($statement, -20.00);
    $payload = statementImportPayload([$line]);

    actingAs($user)->post(route('statements.import-lines', $statement), $payload);
    actingAs($user)->post(route('statements.import-lines', $statement), $payload)
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 0 lines. 1 refused: Only lines that are not in Flusso can be imported. (1)');

    expect(Transaction::query()->count())->toBe(1)
        ->and($account->fresh()->balance)->toBe('980.00');
});

test('a line in another currency than the account is refused and nothing is converted', function () {
    [$user, $account, $statement] = statementImportSetup('RON');
    $euro = statementImportLine($statement, -10.00, ['currency' => 'EUR']);
    $lei = statementImportLine($statement, -11.00, ['currency' => 'RON']);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$euro, $lei]));

    expect(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->firstOrFail()->amount)->toBe('11.00')
        ->and($euro->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($euro->fresh()->matched_transaction_id)->toBeNull()
        ->and($lei->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and($account->fresh()->balance)->toBe('989.00');
});

test('a line without a currency takes the currency of the account', function () {
    [$user, , $statement] = statementImportSetup();
    $line = statementImportLine($statement, -4.00, ['currency' => null]);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line]));

    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and(Transaction::query()->firstOrFail()->currency)->toBe('EUR');
});

test('a zero line is refused while the others go on', function () {
    [$user, , $statement] = statementImportSetup();
    $zero = statementImportLine($statement, 0.00);
    $good = statementImportLine($statement, -5.00);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$zero, $good]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 1 line. 1 refused: A line of 0.00 is not a transaction. (1)');

    expect(Transaction::query()->count())->toBe(1)
        ->and($zero->fresh()->match_status)->toBe(StatementLineStatus::Unmatched);
});

test('an unexpected error rolls the whole batch back, leaving no half-imported line', function () {
    [$user, $account, $statement] = statementImportSetup();
    $first = statementImportLine($statement, -1.00);
    $second = statementImportLine($statement, -2.00);

    $real = app(TransactionService::class);
    $calls = 0;
    $this->mock(TransactionService::class, function ($mock) use ($real, &$calls) {
        $mock->shouldReceive('createTransaction')->andReturnUsing(function (User $user, array $data) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('boom');
            }

            return $real->createTransaction($user, $data);
        });
    });

    $this->withoutExceptionHandling();

    expect(fn () => actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$first, $second])))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Transaction::query()->count())->toBe(0)
        ->and($first->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($first->fresh()->matched_transaction_id)->toBeNull()
        ->and($account->fresh()->balance)->toBe('1000.00');
});

test('a statement without an account needs one to be chosen', function () {
    [$user, $account, $statement] = statementImportSetup(linked: false);
    $line = statementImportLine($statement, -6.00);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$line]))
        ->assertSessionHasErrors('account_id');

    expect(Transaction::query()->count())->toBe(0);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$line], ['account_id' => $account->id]))
        ->assertSessionHasNoErrors();

    expect(Transaction::query()->firstOrFail()->account_id)->toBe($account->id)
        ->and($statement->fresh()->account_id)->toBeNull();
});

test('a statement without an account still detects what is already in the chosen account', function () {
    [$user, $account, $statement] = statementImportSetup(linked: false);
    statementImportTransaction($account, '2024-03-10', 6.00);
    $line = statementImportLine($statement, -6.00);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line], ['account_id' => $account->id]));

    expect(Transaction::query()->count())->toBe(1)
        ->and($line->fresh()->match_status)->toBe(StatementLineStatus::Matched);
});

test('the account of a linked statement cannot be overridden', function () {
    [$user, $account, $statement] = statementImportSetup();
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $line = statementImportLine($statement, -6.00);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line], ['account_id' => $other->id]));

    expect(Transaction::query()->firstOrFail()->account_id)->toBe($account->id);
});

test('an archived account cannot be imported into', function () {
    [$user, $account, $statement] = statementImportSetup();
    $line = statementImportLine($statement, -6.00);
    $account->delete();

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$line]))
        ->assertSessionHasErrors('account_id');

    expect(Transaction::query()->count())->toBe(0);
});

test('another users account is rejected', function () {
    [$user, , $statement] = statementImportSetup(linked: false);
    $foreign = Account::factory()->create(['currency' => 'EUR']);
    $line = statementImportLine($statement, -6.00);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload([$line], ['account_id' => $foreign->id]))
        ->assertSessionHasErrors('account_id');

    expect(Transaction::query()->count())->toBe(0);
});

test('categories are whitelisted: own and of the right kind', function () {
    [$user, , $statement] = statementImportSetup();
    $foreign = Category::factory()->create(['type' => CategoryType::Expense]);
    $line = statementImportLine($statement, -6.00);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), ['lines' => [['id' => $line->id, 'category_id' => $foreign->id]]])
        ->assertSessionHasErrors('lines.0.category_id');

    expect(Transaction::query()->count())->toBe(0);

    $salary = statementImportCategory($user, CategoryType::Income, 'Salary');
    $expense = statementImportLine($statement, -7.00);
    $income = statementImportLine($statement, 50.00);

    actingAs($user)->post(route('statements.import-lines', $statement), [
        'lines' => [
            ['id' => $expense->id, 'category_id' => $salary->id],
            ['id' => $income->id, 'category_id' => $salary->id],
        ],
    ]);

    expect($expense->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($income->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and(Transaction::query()->firstOrFail()->category_id)->toBe($salary->id);
});

test('lines of another statement or another user cannot be imported', function () {
    [$user, , $statement] = statementImportSetup();
    [, , $otherStatement] = statementImportSetup();
    $sameUserOtherStatement = Statement::factory()->create(['user_id' => $user->id]);

    $foreignLine = statementImportLine($otherStatement, -6.00);
    $siblingLine = statementImportLine($sameUserOtherStatement, -6.00);

    foreach ([$foreignLine, $siblingLine] as $line) {
        actingAs($user)
            ->post(route('statements.import-lines', $statement), statementImportPayload([$line]))
            ->assertSessionHasErrors('lines.0.id');
    }

    expect(Transaction::query()->count())->toBe(0);
});

test('another users statement cannot be imported from, ignored in or restored in', function () {
    [$user] = statementImportSetup();
    [, $foreignAccount, $foreignStatement] = statementImportSetup();
    $line = statementImportLine($foreignStatement, -6.00);

    actingAs($user)->post(route('statements.import-lines', $foreignStatement), statementImportPayload([$line]))->assertForbidden();
    actingAs($user)->post(route('statements.ignore-line', [$foreignStatement, $line]))->assertForbidden();
    actingAs($user)->post(route('statements.restore-line', [$foreignStatement, $line]))->assertForbidden();

    expect(Transaction::query()->count())->toBe(0)
        ->and($line->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($foreignAccount->fresh()->balance)->toBe('1000.00');
});

test('guests are sent to the login', function () {
    [, , $statement] = statementImportSetup();
    $line = statementImportLine($statement, -6.00);

    $this->post(route('statements.import-lines', $statement), statementImportPayload([$line]))->assertRedirect(route('login'));
    $this->post(route('statements.ignore-line', [$statement, $line]))->assertRedirect(route('login'));
    $this->post(route('statements.restore-line', [$statement, $line]))->assertRedirect(route('login'));
});

test('only unmatched lines can be imported', function () {
    [$user, $account, $statement] = statementImportSetup();
    $existing = statementImportTransaction($account, '2024-01-01', 1.00);

    $lines = [];

    foreach ([StatementLineStatus::Matched, StatementLineStatus::Imported, StatementLineStatus::Ignored] as $status) {
        $lines[] = statementImportLine($statement, -9.00, [
            'match_status' => $status,
            'matched_transaction_id' => $status === StatementLineStatus::Ignored ? null : $existing->id,
        ]);
    }

    actingAs($user)
        ->post(route('statements.import-lines', $statement), statementImportPayload($lines))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 0 lines. 3 refused: Only lines that are not in Flusso can be imported. (3)');

    expect(Transaction::query()->count())->toBe(1);
});

test('import all takes every unmatched line, with categories from the history', function () {
    [$user, $account, $statement] = statementImportSetup();
    $food = statementImportCategory($user);
    statementImportTransaction($account, '2023-12-01', 40.00, ['description' => 'Lidl Market', 'category_id' => $food->id]);

    $known = statementImportLine($statement, -15.00, ['description' => 'Lidl Market']);
    $unknown = statementImportLine($statement, -16.00, ['description' => 'Something odd']);
    $edited = statementImportLine($statement, -17.00, ['description' => 'Lidl Market']);
    $ignored = statementImportLine($statement, -18.00, ['match_status' => StatementLineStatus::Ignored]);

    actingAs($user)
        ->post(route('statements.import-lines', $statement), [
            'import_all' => true,
            'lines' => [['id' => $edited->id, 'description' => 'Shopping', 'category_id' => null]],
        ])
        ->assertSessionHasNoErrors();

    $byAmount = fn (string $amount) => Transaction::query()->get()->firstWhere(fn (Transaction $transaction) => $transaction->amount === $amount);

    expect($byAmount('15.00')->category_id)->toBe($food->id)
        ->and($byAmount('16.00')->category_id)->toBeNull()
        ->and($byAmount('17.00')->category_id)->toBeNull()
        ->and($byAmount('17.00')->description)->toBe('Shopping')
        ->and($known->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and($unknown->fresh()->match_status)->toBe(StatementLineStatus::Imported)
        ->and($ignored->fresh()->match_status)->toBe(StatementLineStatus::Ignored)
        ->and(Transaction::query()->count())->toBe(4);
});

test('selecting nothing is a validation error', function () {
    [$user, , $statement] = statementImportSetup();

    actingAs($user)
        ->post(route('statements.import-lines', $statement), ['lines' => []])
        ->assertSessionHasErrors('lines');
});

test('a line can be ignored and restored', function () {
    [$user, , $statement] = statementImportSetup();
    $line = statementImportLine($statement, -6.00);

    actingAs($user)->post(route('statements.ignore-line', [$statement, $line]))->assertSessionHas('inertia.flash_data.toast.message', 'Line ignored.');
    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Ignored);

    actingAs($user)->post(route('statements.restore-line', [$statement, $line]))->assertSessionHas('inertia.flash_data.toast.message', 'Line restored.');
    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Unmatched);
});

test('only an unmatched line can be ignored and only an ignored one restored', function () {
    [$user, $account, $statement] = statementImportSetup();
    $transaction = statementImportTransaction($account, '2024-03-10', 6.00);
    $matched = statementImportLine($statement, -6.00, ['match_status' => StatementLineStatus::Matched, 'matched_transaction_id' => $transaction->id]);
    $imported = statementImportLine($statement, -7.00, ['match_status' => StatementLineStatus::Imported, 'matched_transaction_id' => $transaction->id]);

    actingAs($user)->post(route('statements.ignore-line', [$statement, $matched]));
    actingAs($user)->post(route('statements.ignore-line', [$statement, $imported]));
    actingAs($user)->post(route('statements.restore-line', [$statement, $matched]));

    expect($matched->fresh()->match_status)->toBe(StatementLineStatus::Matched)
        ->and($imported->fresh()->match_status)->toBe(StatementLineStatus::Imported);
});

test('a restored line that is already in the app comes back matched', function () {
    [$user, $account, $statement] = statementImportSetup();
    $transaction = statementImportTransaction($account, '2024-03-10', 6.00);
    $line = statementImportLine($statement, -6.00, ['match_status' => StatementLineStatus::Ignored]);

    actingAs($user)->post(route('statements.restore-line', [$statement, $line]));

    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Matched)
        ->and($line->fresh()->matched_transaction_id)->toBe($transaction->id);
});

test('a line of another statement is not found under this one', function () {
    [$user, , $statement] = statementImportSetup();
    $other = Statement::factory()->create(['user_id' => $user->id]);
    $line = statementImportLine($other, -6.00);

    actingAs($user)->post(route('statements.ignore-line', [$statement, $line]))->assertNotFound();

    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Unmatched);
});

test('deleting an imported transaction returns its line to unmatched on the next match run', function (bool $forceDelete) {
    [$user, $account, $statement] = statementImportSetup();
    $line = statementImportLine($statement, -6.00);

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line]));

    $transaction = Transaction::query()->firstOrFail();
    expect($account->fresh()->balance)->toBe('994.00');

    if ($forceDelete) {
        $transaction->forceDelete();
    } else {
        app(TransactionService::class)->deleteTransaction($transaction);
    }

    app(StatementMatcher::class)->match($statement);

    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($line->fresh()->matched_transaction_id)->toBeNull()
        ->and($account->fresh()->balance)->toBe('1000.00');
})->with(['soft delete' => [false], 'force delete' => [true]]);

test('the page offers categories of the owner only and suggests one from the history', function () {
    [$user, $account, $statement] = statementImportSetup();
    $food = statementImportCategory($user);
    Category::factory()->create(['type' => CategoryType::Expense, 'name' => 'Foreign']);
    statementImportTransaction($account, '2023-12-01', 40.00, ['description' => 'Lidl Market', 'category_id' => $food->id]);
    $line = statementImportLine($statement, -15.00, ['description' => 'Lidl Market']);

    actingAs($user)
        ->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page
            ->component('Statements/Show')
            ->has('categories', 1)
            ->where('categories.0.id', $food->id)
            ->where('lines.data.0.suggested_category_id', $food->id)
            ->where('statement.can_reparse', true));

    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line]));

    actingAs($user)
        ->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page
            ->where('statement.counts.imported', 1)
            ->where('statement.can_reparse', false));
});
