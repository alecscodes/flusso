<?php

use App\Ai\Agents\QuickEntryAgent;
use App\Enums\AiFeature;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Carbon;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * @param  array<string, bool>  $features
 */
function quickEntryEnableAi(array $features = []): void
{
    app(AiSettings::class)->save([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => [...collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(), ...$features],
    ]);
}

function quickEntryAccount(User $user, string $name, string $currency = 'EUR'): Account
{
    return Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'currency' => $currency]);
}

function quickEntryCategory(User $user, string $name, string $type = 'expense'): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type]);
}

/**
 * @return array<string, mixed>
 */
function quickEntryParse(User $user, string $text): array
{
    return actingAs($user)->postJson(route('transactions.parse-text'), ['text' => $text])
        ->assertOk()
        ->json();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-18 12:00:00'));
});

test('amounts keep exactly what was typed', function (string $text, string $amount) {
    $result = quickEntryParse(User::factory()->create(), $text);

    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['amount'])->toBe($amount)
        ->and($result['entries'][0]['type'])->toBe('expense')
        ->and($result['entries'][0]['description'])->toBe('cafea');
})->with([
    'comma decimals' => ['cafea 2,5', '2.5'],
    'dot decimals' => ['cafea 2.5', '2.5'],
    'trailing zero stays' => ['cafea 13.70', '13.70'],
    'whole number' => ['cafea 17', '17'],
    'euro sign after' => ['cafea 2.50€', '2.50'],
    'euro sign before' => ['cafea €2,5', '2.5'],
    'eur word' => ['cafea 3 eur', '3'],
    'ron word' => ['cafea 3 ron', '3'],
    'lei word' => ['cafea 3,10 lei', '3.10'],
    'thousands and decimals' => ['cafea 1.234,56', '1234.56'],
    'english thousands' => ['cafea 1,234.56', '1234.56'],
]);

test('an amount with more than two decimals is not guessed', function () {
    $result = quickEntryParse(User::factory()->create(), 'cafea 2.5555');

    expect($result['entries'])->toBeEmpty()
        ->and($result['unparsed'])->toBe(['cafea 2.5555']);
});

test('a currency word never converts the amount, even next to an account of another currency', function () {
    $user = User::factory()->create();
    $ron = quickEntryAccount($user, 'Cash RON', 'RON');

    $result = quickEntryParse($user, 'cafea 10 eur cash ron');

    expect($result['entries'][0])->toMatchArray(['amount' => '10', 'account_id' => $ron->id]);
});

test('accounts are matched by their own names, ignoring case and diacritics', function () {
    $user = User::factory()->create();
    $cash = quickEntryAccount($user, 'Cash');
    $savings = quickEntryAccount($user, 'Economii Țară');

    expect(quickEntryParse($user, 'cafea 2.5 CASH')['entries'][0])->toMatchArray(['account_id' => $cash->id, 'description' => 'cafea'])
        ->and(quickEntryParse($user, 'pizza 17 economii tara')['entries'][0])->toMatchArray(['account_id' => $savings->id, 'description' => 'pizza'])
        ->and(quickEntryParse($user, 'pizza 17')['entries'][0]['account_id'])->toBeNull();
});

test('a word shared by two accounts is told apart by the currency word', function () {
    $user = User::factory()->create();
    quickEntryAccount($user, 'Revolut EUR', 'EUR');
    $ron = quickEntryAccount($user, 'Revolut RON', 'RON');

    expect(quickEntryParse($user, 'mama amazon 40 revolut')['entries'][0]['account_id'])->toBeNull()
        ->and(quickEntryParse($user, 'mama amazon 40 revolut lei')['entries'][0])->toMatchArray(['account_id' => $ron->id, 'description' => 'mama amazon']);
});

test('an archived account is not matched', function () {
    $user = User::factory()->create();
    $old = quickEntryAccount($user, 'Old wallet');
    $old->delete();

    expect(quickEntryParse($user, 'cafea 2 old wallet')['entries'][0])->toMatchArray(['account_id' => null, 'description' => 'cafea old wallet']);
});

test('relative and explicit dates are read in the app timezone', function (string $text, string $date) {
    $result = quickEntryParse(User::factory()->create(), $text);

    expect($result['entries'][0]['date'])->toBe($date)
        ->and($result['entries'][0]['description'])->toBe('cafea');
})->with([
    'today' => ['cafea 2 azi', '2026-03-18'],
    'english today' => ['cafea 2 today', '2026-03-18'],
    'no date' => ['cafea 2', '2026-03-18'],
    'yesterday' => ['cafea 2 ieri', '2026-03-17'],
    'english yesterday' => ['cafea 2 yesterday', '2026-03-17'],
    'yesterday evening' => ['cafea 2 ieri seara', '2026-03-17'],
    'day before yesterday' => ['cafea 2 alaltaieri', '2026-03-16'],
    'weekday in the past' => ['cafea 2 vineri', '2026-03-13'],
    'same weekday is today' => ['cafea 2 miercuri', '2026-03-18'],
    'slash day month' => ['cafea 2 05/03', '2026-03-05'],
    'slash in the future is last year' => ['cafea 2 25/03', '2025-03-25'],
    'dotted with year' => ['cafea 2 05.03.2026', '2026-03-05'],
    'iso' => ['cafea 2 2026-01-31', '2026-01-31'],
    'dotted day month next to an amount' => ['cafea 12.03 2,5', '2026-03-12'],
]);

test('the day is the one of the app timezone, not of UTC', function () {
    config(['app.timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(Carbon::parse('2026-03-17 23:30:00', 'UTC'));

    expect(quickEntryParse(User::factory()->create(), 'cafea 2 azi')['entries'][0]['date'])->toBe('2026-03-18');
});

test('several entries split on new lines and semicolons, and what has no amount is handed back', function () {
    $user = User::factory()->create();
    $cash = quickEntryAccount($user, 'Cash');

    $result = quickEntryParse($user, "cafea 2,5 cash\npizza 17; ceva fara suma;taxi 8 lei ieri");

    expect($result['entries'])->toHaveCount(3)
        ->and($result['entries'][0])->toMatchArray(['amount' => '2.5', 'account_id' => $cash->id])
        ->and($result['entries'][1])->toMatchArray(['amount' => '17', 'description' => 'pizza'])
        ->and($result['entries'][2])->toMatchArray(['amount' => '8', 'date' => '2026-03-17'])
        ->and($result['unparsed'])->toBe(['ceva fara suma']);
});

test('income is told by its words or a plus sign', function (string $text, string $description) {
    $result = quickEntryParse(User::factory()->create(), $text);

    expect($result['entries'][0]['type'])->toBe('income')
        ->and($result['entries'][0]['description'])->toBe($description);
})->with([
    'primit' => ['primit 200 de la mama', 'de la mama'],
    'incasat' => ['incasat 50 chirie', 'chirie'],
    'salary word' => ['salary 1500', 'salary'],
    'stipendio word' => ['stipendio 1.500,50', 'stipendio'],
    'plus on the amount' => ['+200 bonus', 'bonus'],
    'leading plus' => ['+ 200 bonus', 'bonus'],
]);

test('a plus in the middle of a line is not income', function () {
    expect(quickEntryParse(User::factory()->create(), 'pizza + cola 17')['entries'][0])
        ->toMatchArray(['type' => 'expense', 'description' => 'pizza cola']);
});

test('the category comes from the history first', function () {
    $user = User::factory()->create();
    $food = quickEntryCategory($user, 'Food');
    $account = quickEntryAccount($user, 'Cash');
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'category_id' => $food->id, 'type' => 'expense', 'description' => 'Pizza', 'amount' => 10]);

    $result = quickEntryParse($user, 'pizza 17 cash');

    expect($result['entries'][0])->toMatchArray(['category_id' => $food->id, 'source' => 'history']);
});

test('without a match and with AI off the category is left empty and the model is never asked', function () {
    $user = User::factory()->create();
    quickEntryCategory($user, 'Food');
    QuickEntryAgent::fake();

    $result = quickEntryParse($user, 'zorzon 17');

    expect($result['entries'][0])->toMatchArray(['category_id' => null, 'source' => null]);
    QuickEntryAgent::assertNeverPrompted();
});

test('the model is not asked when its feature is switched off', function () {
    $user = User::factory()->create();
    quickEntryCategory($user, 'Food');
    quickEntryEnableAi(['quick_entry' => false]);
    QuickEntryAgent::fake();

    quickEntryParse($user, 'zorzon 17');

    QuickEntryAgent::assertNeverPrompted();
});

test('the model picks a category for what history cannot, and its answer is whitelisted', function () {
    $user = User::factory()->create();
    $food = quickEntryCategory($user, 'Food');
    $salary = quickEntryCategory($user, 'Salary', 'income');
    $foreign = quickEntryCategory(User::factory()->create(), 'Foreign');
    quickEntryEnableAi();

    QuickEntryAgent::fake([
        ['category_id' => $food->id],
        ['category_id' => $foreign->id],
        ['category_id' => $salary->id],
        ['category_id' => 0],
    ]);

    $result = quickEntryParse($user, 'zorzon 17;blabla 3;qwerty 4;asdfgh 5');

    expect($result['entries'][0])->toMatchArray(['category_id' => $food->id, 'source' => 'ai'])
        ->and($result['entries'][1])->toMatchArray(['category_id' => null, 'source' => null])
        // An income category does not fit an expense.
        ->and($result['entries'][2])->toMatchArray(['category_id' => null, 'source' => null])
        ->and($result['entries'][3])->toMatchArray(['category_id' => null, 'source' => null]);
});

test('the prompt holds only the leftover description and the category names', function () {
    $user = User::factory()->create();
    $food = quickEntryCategory($user, 'Food');
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Secret savings', 'balance' => 987.65]);
    quickEntryEnableAi();
    QuickEntryAgent::fake([['category_id' => $food->id]]);

    quickEntryParse($user, 'zorzon 12.34 secret savings ieri');

    QuickEntryAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Food')
        && $prompt->contains('zorzon')
        && ! $prompt->contains('Secret')
        && ! $prompt->contains('savings')
        && ! $prompt->contains('12.34')
        && ! $prompt->contains('987')
        && ! $prompt->contains('2026'));
});

test('another user never matches: accounts and categories stay private', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignAccount = quickEntryAccount($other, 'Hidden vault');
    quickEntryAccount($other, 'Cash');
    $foreignCategory = quickEntryCategory($other, 'Pizza night');
    Transaction::factory()->create(['user_id' => $other->id, 'account_id' => $foreignAccount->id, 'category_id' => $foreignCategory->id, 'type' => 'expense', 'description' => 'Pizza', 'amount' => 10]);

    $result = quickEntryParse($user, 'pizza 17 hidden vault; cafea 2 cash');

    expect($result['entries'][0])->toMatchArray(['account_id' => null, 'category_id' => null, 'description' => 'pizza hidden vault'])
        ->and($result['entries'][1])->toMatchArray(['account_id' => null, 'description' => 'cafea cash']);
});

test('nothing is saved', function () {
    $user = User::factory()->create();
    quickEntryAccount($user, 'Cash');

    quickEntryParse($user, 'cafea 2.5 cash');

    expect(Transaction::count())->toBe(0);
});

test('the text is validated', function (mixed $text) {
    actingAs(User::factory()->create())
        ->postJson(route('transactions.parse-text'), ['text' => $text])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('text');
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 501)],
    'not text' => [['a']],
]);

test('only a signed-in user can parse and the endpoint is throttled', function () {
    postJson(route('transactions.parse-text'), ['text' => 'cafea 2'])->assertUnauthorized();

    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        actingAs($user)->postJson(route('transactions.parse-text'), ['text' => 'cafea 2'])->assertOk();
    }

    actingAs($user)->postJson(route('transactions.parse-text'), ['text' => 'cafea 2'])->assertStatus(429);
});
