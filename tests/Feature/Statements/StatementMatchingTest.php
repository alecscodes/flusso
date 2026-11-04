<?php

use App\Enums\StatementLineStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statements\StatementMatcher;

/**
 * @return array{0: User, 1: Account, 2: Statement}
 */
function statementsMatchSetup(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $statement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);

    return [$user, $account, $statement];
}

function statementsLine(Statement $statement, string $date, float $amount, string $description = 'Something'): StatementLine
{
    return StatementLine::factory()->create([
        'statement_id' => $statement->id,
        'user_id' => $statement->user_id,
        'posted_on' => $date,
        'amount' => $amount,
        'description' => $description,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function statementsTransaction(Account $account, string $date, float $amount, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'date' => $date,
        'amount' => $amount,
        'description' => null,
        ...$attributes,
    ]);
}

test('a line matches a transaction with the same amount within three days', function (string $transactionDate, bool $matches) {
    [, $account, $statement] = statementsMatchSetup();
    $line = statementsLine($statement, '2024-03-10', -12.50);
    $transaction = statementsTransaction($account, $transactionDate, 12.50);

    $result = app(StatementMatcher::class)->match($statement);

    expect($result)->toBe(['matched' => $matches ? 1 : 0, 'unmatched' => $matches ? 0 : 1])
        ->and($line->fresh()->match_status)->toBe($matches ? StatementLineStatus::Matched : StatementLineStatus::Unmatched)
        ->and($line->fresh()->matched_transaction_id)->toBe($matches ? $transaction->id : null);
})->with([
    'same day' => ['2024-03-10', true],
    'three days before' => ['2024-03-07', true],
    'three days after' => ['2024-03-13', true],
    'four days after' => ['2024-03-14', false],
    'a month away' => ['2024-04-10', false],
]);

test('the amount must be the same to the cent', function () {
    [, $account, $statement] = statementsMatchSetup();
    statementsLine($statement, '2024-03-10', -12.50);
    statementsTransaction($account, '2024-03-10', 12.51);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 0, 'unmatched' => 1]);
});

test('income and expenses both match on the absolute amount', function () {
    [, $account, $statement] = statementsMatchSetup();
    statementsLine($statement, '2024-03-10', 1500.00);
    statementsTransaction($account, '2024-03-10', 1500.00, ['type' => 'income']);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 1, 'unmatched' => 0]);
});

test('deleted transactions and other accounts or users never match', function () {
    [$user, $account, $statement] = statementsMatchSetup();
    statementsLine($statement, '2024-03-10', -20.00);

    statementsTransaction($account, '2024-03-10', 20.00)->delete();
    statementsTransaction(Account::factory()->create(['user_id' => $user->id]), '2024-03-10', 20.00);
    statementsTransaction(Account::factory()->create(), '2024-03-10', 20.00);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 0, 'unmatched' => 1]);
});

test('a transaction matches only one line', function () {
    [, $account, $statement] = statementsMatchSetup();
    $first = statementsLine($statement, '2024-03-10', -2.00, 'Coffee');
    $second = statementsLine($statement, '2024-03-10', -2.00, 'Coffee');
    $transaction = statementsTransaction($account, '2024-03-10', 2.00);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 1, 'unmatched' => 1])
        ->and($first->fresh()->matched_transaction_id)->toBe($transaction->id)
        ->and($second->fresh()->matched_transaction_id)->toBeNull();
});

test('a transaction already matched by another statement is not used again', function () {
    [$user, $account, $statement] = statementsMatchSetup();
    $other = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    $transaction = statementsTransaction($account, '2024-03-10', 2.00);

    statementsLine($other, '2024-03-10', -2.00);
    statementsLine($statement, '2024-03-10', -2.00);

    $matcher = app(StatementMatcher::class);

    expect($matcher->match($other))->toBe(['matched' => 1, 'unmatched' => 0])
        ->and($matcher->match($statement))->toBe(['matched' => 0, 'unmatched' => 1])
        ->and(StatementLine::query()->where('matched_transaction_id', $transaction->id)->count())->toBe(1);
});

test('ambiguous candidates leave the line unmatched and are listed as candidates', function () {
    [, $account, $statement] = statementsMatchSetup();
    $line = statementsLine($statement, '2024-03-10', -9.99, 'CARD PAYMENT');
    $a = statementsTransaction($account, '2024-03-10', 9.99, ['description' => 'Lunch']);
    $b = statementsTransaction($account, '2024-03-10', 9.99, ['description' => 'Book']);

    $matcher = app(StatementMatcher::class);

    expect($matcher->match($statement))->toBe(['matched' => 0, 'unmatched' => 1]);

    $candidates = $matcher->candidates($statement);

    expect(array_keys($candidates))->toBe([$line->id])
        ->and(collect($candidates[$line->id])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('a similar description or the exact date settles an ambiguity', function () {
    [, $account, $statement] = statementsMatchSetup();
    $netflix = statementsLine($statement, '2024-03-10', -12.99, 'NETFLIX.COM 12345');
    $rent = statementsTransaction($account, '2024-03-10', 12.99, ['description' => 'Rent share']);
    $subscription = statementsTransaction($account, '2024-03-10', 12.99, ['description' => 'Netflix subscription']);

    $byDate = statementsLine($statement, '2024-03-20', -5.00, 'Unknown');
    statementsTransaction($account, '2024-03-22', 5.00);
    $exact = statementsTransaction($account, '2024-03-20', 5.00);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 2, 'unmatched' => 0])
        ->and($netflix->fresh()->matched_transaction_id)->toBe($subscription->id)
        ->and($byDate->fresh()->matched_transaction_id)->toBe($exact->id)
        ->and($rent->id)->not->toBe($subscription->id);
});

test('a transfer counts for both of its accounts', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id]);
    $to = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->transfer($to, 100)->create([
        'user_id' => $user->id, 'account_id' => $from->id, 'amount' => 100, 'date' => '2024-03-10', 'description' => null,
    ]);

    $outStatement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $from->id]);
    $inStatement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $to->id]);
    statementsLine($outStatement, '2024-03-10', -100.00);
    statementsLine($inStatement, '2024-03-11', 100.00);

    $matcher = app(StatementMatcher::class);

    expect($matcher->match($outStatement))->toBe(['matched' => 1, 'unmatched' => 0])
        ->and($matcher->match($inStatement))->toBe(['matched' => 1, 'unmatched' => 0]);
});

test('a transfer between currencies matches the amount that arrived', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $to = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']);
    Transaction::factory()->transfer($to, 497.50)->create([
        'user_id' => $user->id, 'account_id' => $from->id, 'amount' => 100, 'date' => '2024-03-10', 'description' => null,
    ]);

    $inStatement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $to->id]);
    statementsLine($inStatement, '2024-03-10', 497.50);

    expect(app(StatementMatcher::class)->match($inStatement))->toBe(['matched' => 1, 'unmatched' => 0]);
});

test('imported and ignored lines are left alone and a statement without an account matches nothing', function () {
    [, $account, $statement] = statementsMatchSetup();
    $imported = statementsLine($statement, '2024-03-10', -7.00);
    $imported->update(['match_status' => StatementLineStatus::Imported]);
    $open = statementsLine($statement, '2024-03-10', -8.00);
    statementsTransaction($account, '2024-03-10', 7.00);
    statementsTransaction($account, '2024-03-10', 8.00);

    $matcher = app(StatementMatcher::class);

    expect($matcher->match($statement))->toBe(['matched' => 1, 'unmatched' => 0])
        ->and($imported->fresh()->match_status)->toBe(StatementLineStatus::Imported);

    $statement->update(['account_id' => null]);

    expect($matcher->match($statement->refresh()))->toBe(['matched' => 0, 'unmatched' => 1])
        ->and($open->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and($open->fresh()->matched_transaction_id)->toBeNull();
});

test('matching never changes transactions', function () {
    [, $account, $statement] = statementsMatchSetup();
    statementsLine($statement, '2024-03-10', -12.50);
    $transaction = statementsTransaction($account, '2024-03-10', 12.50);
    $before = $transaction->fresh()->getAttributes();

    app(StatementMatcher::class)->match($statement);

    expect($transaction->fresh()->getAttributes())->toBe($before);
});

test('the show page tells how many lines are matched and what is missing from the app', function () {
    [$user, $account, $statement] = statementsMatchSetup();
    statementsLine($statement, '2024-03-10', -12.50, 'Pharmacy');
    $missing = statementsLine($statement, '2024-03-11', -99.00, 'Fine');
    statementsTransaction($account, '2024-03-10', 12.50);
    app(StatementMatcher::class)->match($statement);

    test()->actingAs($user)->get(route('statements.show', [$statement, 'status' => 'unmatched']))
        ->assertInertia(fn ($page) => $page
            ->where('statement.counts.matched', 1)
            ->where('statement.counts.unmatched', 1)
            ->has('lines.data', 1)
            ->where('lines.data.0.id', $missing->id));
});
