<?php

use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Statements\StatementSearch;
use App\Services\Statements\StatementSearchIndex;

use function Pest\Laravel\actingAs;

/**
 * A statement with its full text and lines, put in the search index.
 *
 * @param  list<array{0: string, 1: string, 2: float|int}>  $lines  date, description, amount
 * @param  array<string, mixed>  $attributes
 */
function statementsArchive(User $user, string $text, array $lines = [], array $attributes = []): Statement
{
    $statement = Statement::factory()->create([
        'user_id' => $user->id,
        'extracted_text' => $text,
        ...$attributes,
    ]);

    foreach ($lines as [$date, $description, $amount]) {
        StatementLine::factory()->create([
            'statement_id' => $statement->id,
            'user_id' => $user->id,
            'posted_on' => $date,
            'description' => $description,
            'amount' => $amount,
        ]);
    }

    app(StatementSearchIndex::class)->index($statement);

    return $statement;
}

/**
 * Makes the search use LIKE, as on a SQLite without FTS5.
 */
function statementsWithoutFts(): void
{
    app()->instance(StatementSearchIndex::class, new class extends StatementSearchIndex
    {
        public function isAvailable(): bool
        {
            return false;
        }
    });
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function statementsSearchResults(User $user, array $query): array
{
    $page = actingAs($user)->get(route('statements.index', $query))->assertSuccessful()->viewData('page');

    return $page['props']['search'];
}

dataset('statementsSearchBackends', ['fts' => [false], 'like' => [true]]);

test('a search finds a word in the file text and in line descriptions, with highlights', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $letter = statementsArchive($user, 'Comunicazione: sanzione amministrativa codice della strada verbale 12345');
    $bank = statementsArchive($user, 'Estratto conto gennaio', [['2024-01-10', 'PAGAMENTO SANZIONE COMUNE DI ROMA', -42.00]]);
    statementsArchive($user, 'Estratto conto febbraio', [['2024-02-10', 'Supermercato', -10.00]]);

    $search = statementsSearchResults($user, ['q' => 'sanzione']);

    expect(collect($search['results'])->pluck('id')->sort()->values()->all())->toBe(collect([$letter->id, $bank->id])->sort()->values()->all());

    $fromText = collect($search['results'])->firstWhere('id', $letter->id);
    $fromLine = collect($search['results'])->firstWhere('id', $bank->id);

    expect(collect($fromText['snippet'])->contains(fn ($segment) => $segment['hit'] && strtolower($segment['text']) === 'sanzione'))->toBeTrue()
        ->and($fromLine['snippet'])->toBeNull()
        ->and($fromLine['lines'])->toHaveCount(1)
        ->and($fromLine['lines'][0]['posted_on'])->toBe('2024-01-10')
        ->and((float) $fromLine['lines'][0]['amount'])->toBe(-42.0)
        ->and(collect($fromLine['lines'][0]['description'])->where('hit', true)->pluck('text')->map(fn ($text) => strtolower($text))->all())->toBe(['sanzione']);
})->with('statementsSearchBackends');

test('every word must match and a word start is enough', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $match = statementsArchive($user, 'x', [['2024-01-10', 'Netflix subscription January', -12.99]]);
    statementsArchive($user, 'x', [['2024-01-11', 'Netflix refund', 12.99]]);

    $results = statementsSearchResults($user, ['q' => 'netfl subscr'])['results'];

    expect(collect($results)->pluck('id')->all())->toBe([$match->id]);
})->with('statementsSearchBackends');

test('characters with a meaning in the search syntax cannot break a search', function (string $query, bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    statementsArchive($user, 'Pagamento C&A "negozio" O\'Brien 50% (rata) AND OR NEAR', [['2024-01-10', 'C&A "sale" 50%', -50.00]]);

    actingAs($user)->get(route('statements.index', ['q' => $query]))->assertSuccessful();
})->with([
    '"', 'AND', 'OR NOT', '*', '(', ')', 'a"b', 'NEAR(', '---', 'col:val', '50%', "O'Brien", '\\', 'C&A', '^start', '{x}',
])->with('statementsSearchBackends');

test('quotes and operators are searched as plain words', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $match = statementsArchive($user, 'x', [['2024-01-10', 'Pizza AND beer OR wine', -20.00]]);
    statementsArchive($user, 'x', [['2024-01-11', 'Pizza only', -20.00]]);

    expect(collect(statementsSearchResults($user, ['q' => 'pizza AND beer'])['results'])->pluck('id')->all())->toBe([$match->id])
        ->and(statementsSearchResults($user, ['q' => '"'])['results'])->toBe([])
        ->and(statementsSearchResults($user, ['q' => '---'])['results'])->toBe([]);
})->with('statementsSearchBackends');

test('a search never returns another user\'s statements', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $other = User::factory()->create();
    statementsArchive($other, 'secret multa', [['2024-01-10', 'MULTA', -80.00]]);
    $own = statementsArchive($user, 'multa mia');

    $results = statementsSearchResults($user, ['q' => 'multa'])['results'];

    expect(collect($results)->pluck('id')->all())->toBe([$own->id])
        ->and($results[0]['lines'])->toBe([]);
})->with('statementsSearchBackends');

test('the filters narrow by institution, account, period and amount', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $old = statementsArchive($user, 'x', [['2022-05-05', 'Tassa rifiuti', -300.00]], [
        'institution' => 'Old Bank', 'period_start' => '2022-05-01', 'period_end' => '2022-05-31',
    ]);
    $recent = statementsArchive($user, 'x', [['2024-05-05', 'Tassa rifiuti', -30.00]], [
        'institution' => 'Revolut', 'account_id' => $account->id, 'period_start' => '2024-05-01', 'period_end' => '2024-05-31',
    ]);

    $ids = fn (array $query) => collect(statementsSearchResults($user, $query)['results'])->pluck('id')->sort()->values()->all();

    expect($ids(['q' => 'tassa']))->toBe([$old->id, $recent->id])
        ->and($ids(['q' => 'tassa', 'institution' => 'revol']))->toBe([$recent->id])
        ->and($ids(['q' => 'tassa', 'account_id' => $account->id]))->toBe([$recent->id])
        ->and($ids(['q' => 'tassa', 'date_from' => '2024-01-01']))->toBe([$recent->id])
        ->and($ids(['q' => 'tassa', 'date_to' => '2023-01-01']))->toBe([$old->id])
        ->and($ids(['q' => 'tassa', 'amount_min' => 100]))->toBe([$old->id])
        ->and($ids(['q' => 'tassa', 'amount_max' => 100]))->toBe([$recent->id])
        ->and($ids(['amount_min' => 25, 'amount_max' => 35]))->toBe([$recent->id])
        ->and($ids(['institution' => 'bank']))->toBe([$old->id])
        ->and($ids(['date_from' => '2024-05-15']))->toBe([$recent->id]);
})->with('statementsSearchBackends');

test('an empty search lists no results and a bad date range is rejected', function () {
    $user = User::factory()->create();
    statementsArchive($user, 'anything');

    actingAs($user)->get(route('statements.index'))->assertInertia(fn ($page) => $page->where('search', null));
    actingAs($user)->get(route('statements.index', ['date_from' => '2024-05-01', 'date_to' => '2024-01-01']))->assertSessionHasErrors('date_to');
});

test('search words and highlights are built safely', function () {
    expect(StatementSearch::terms("  pizza   \"  --  beer  "))->toBe(['pizza', 'beer']);

    $segments = StatementSearch::highlight('Pizza <b>Margherita</b>', ['pizza', 'b']);

    expect(collect($segments)->pluck('text')->implode(''))->toBe('Pizza <b>Margherita</b>')
        ->and($segments[0])->toBe(['text' => 'Pizza', 'hit' => true]);
});

test('the FTS expression quotes every term', function () {
    expect(StatementSearchIndex::matchExpression(['a"b', 'AND']))->toBe('"a""b"* "AND"*');
});
