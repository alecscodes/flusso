<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionShortcutService;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Books the same transaction once a day for the last `$times` days.
 *
 * @param  array<string, mixed>  $overrides
 */
function shortcutsBook(User $user, Account $account, ?Category $category, int $times, array $overrides = []): void
{
    for ($day = 0; $day < $times; $day++) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'type' => 'expense',
            'amount' => 3.5,
            'description' => 'Coffee',
            'date' => now()->subDays($day)->toDateString(),
            ...$overrides,
        ]);
    }
}

/**
 * @return array{0: User, 1: Account, 2: Category}
 */
function shortcutsSetup(): array
{
    $user = User::factory()->create();

    return [
        $user,
        Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']),
        Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Food']),
    ];
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-06-15 20:00:00')));

test('a transaction needs five occurrences in the last 30 days to become a shortcut', function () {
    [$user, $account, $category] = shortcutsSetup();

    shortcutsBook($user, $account, $category, 4);
    expect(app(TransactionShortcutService::class)->shortcutsFor($user))->toBeEmpty();

    shortcutsBook($user, $account, $category, 1, ['date' => now()->subDays(10)->toDateString()]);

    $shortcuts = app(TransactionShortcutService::class)->shortcutsFor($user);

    expect($shortcuts)->toHaveCount(1)
        ->and($shortcuts[0])->toMatchArray([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => '3.50',
            'description' => 'Coffee',
            'count' => 5,
        ]);
});

test('occurrences older than 30 days do not count', function () {
    [$user, $account, $category] = shortcutsSetup();

    shortcutsBook($user, $account, $category, 3);

    foreach ([35, 40, 45, 50] as $daysAgo) {
        shortcutsBook($user, $account, $category, 1, ['date' => now()->subDays($daysAgo)->toDateString()]);
    }

    expect(app(TransactionShortcutService::class)->shortcutsFor($user))->toBeEmpty();
});

test('descriptions are grouped ignoring case, diacritics and punctuation, and an amount of its own makes another shortcut', function () {
    [$user, $account, $category] = shortcutsSetup();

    foreach (['Cumpărături', 'cumparaturi', 'CUMPARATURI!', ' Cumparaturi ', 'Cumpărături.'] as $day => $description) {
        shortcutsBook($user, $account, $category, 1, ['description' => $description, 'date' => now()->subDays($day)->toDateString()]);
    }

    shortcutsBook($user, $account, $category, 5, ['description' => 'Cumparaturi', 'amount' => 20]);

    $shortcuts = app(TransactionShortcutService::class)->shortcutsFor($user);

    expect($shortcuts)->toHaveCount(2)
        ->and($shortcuts->pluck('amount')->all())->toEqualCanonicalizing(['20.00', '3.50']);
});

test('shortcuts are ranked by frequency and capped at five', function () {
    [$user, $account, $category] = shortcutsSetup();

    foreach (range(1, 7) as $index) {
        shortcutsBook($user, $account, $category, 4 + $index, ['description' => "Place {$index}"]);
    }

    $shortcuts = app(TransactionShortcutService::class)->shortcutsFor($user);

    expect($shortcuts)->toHaveCount(5)
        ->and($shortcuts->pluck('description')->all())->toBe(['Place 7', 'Place 6', 'Place 5', 'Place 4', 'Place 3']);
});

test('transfers, archived accounts and archived categories are never offered', function () {
    [$user, $account, $category] = shortcutsSetup();
    $archivedAccount = Account::factory()->create(['user_id' => $user->id]);
    $archivedCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $to = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']);

    shortcutsBook($user, $archivedAccount, $category, 5, ['description' => 'Old account']);
    shortcutsBook($user, $account, $archivedCategory, 5, ['description' => 'Old category']);

    for ($day = 0; $day < 5; $day++) {
        Transaction::factory()->transfer($to)->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'amount' => 10,
            'date' => now()->subDays($day)->toDateString(),
        ]);
    }

    $archivedAccount->delete();
    $archivedCategory->delete();

    expect(app(TransactionShortcutService::class)->shortcutsFor($user))->toBeEmpty();
});

test('time of day boosts a pattern only when most of its occurrences share a window around now', function () {
    [$user, $account, $category] = shortcutsSetup();

    // Usually booked around noon: 4 of 5 between 10:00 and 12:59.
    foreach ([10, 11, 12, 12, 3] as $day => $hour) {
        shortcutsBook($user, $account, $category, 1, [
            'description' => 'Lunch',
            'date' => now()->subDays($day)->toDateString(),
            'created_at' => now()->subDays($day)->setTime($hour, 15),
        ]);
    }

    // Booked more often, but all over the day.
    foreach ([1, 5, 9, 14, 18, 22] as $day => $hour) {
        shortcutsBook($user, $account, $category, 1, [
            'description' => 'Snack',
            'date' => now()->subDays($day)->toDateString(),
            'created_at' => now()->subDays($day)->setTime($hour, 15),
        ]);
    }

    $service = app(TransactionShortcutService::class);

    // In the evening time is ignored: the more frequent Snack comes first.
    expect($service->shortcutsFor($user)->pluck('description')->all())->toBe(['Snack', 'Lunch'])
        ->and($service->shortcutsFor($user)->pluck('boosted')->all())->toBe([false, false]);

    // Around noon Lunch is boosted (5 x 1.25 > 6) and Snack is not.
    $this->travelTo(Carbon::parse('2026-06-15 12:30:00'));

    $shortcuts = $service->shortcutsFor($user);

    expect($shortcuts->pluck('description')->all())->toBe(['Lunch', 'Snack'])
        ->and($shortcuts->pluck('boosted')->all())->toBe([true, false]);
});

test('less than 70 percent of occurrences in the window is no time pattern', function () {
    [$user, $account, $category] = shortcutsSetup();

    foreach ([10, 11, 12, 3, 20] as $day => $hour) {
        shortcutsBook($user, $account, $category, 1, [
            'date' => now()->subDays($day)->toDateString(),
            'created_at' => now()->subDays($day)->setTime($hour, 0),
        ]);
    }

    $this->travelTo(Carbon::parse('2026-06-15 11:00:00'));

    expect(app(TransactionShortcutService::class)->shortcutsFor($user)[0]['boosted'])->toBeFalse();
});

test('the window wraps around midnight', function () {
    [$user, $account, $category] = shortcutsSetup();

    foreach ([23, 23, 0, 0, 1] as $day => $hour) {
        shortcutsBook($user, $account, $category, 1, [
            'date' => now()->subDays($day)->toDateString(),
            'created_at' => now()->subDays($day)->setTime($hour, 0),
        ]);
    }

    $this->travelTo(Carbon::parse('2026-06-15 00:30:00'));

    expect(app(TransactionShortcutService::class)->shortcutsFor($user)[0]['boosted'])->toBeTrue();
});

test('the shortcuts endpoint returns only the signed-in users patterns', function () {
    [$user, $account, $category] = shortcutsSetup();
    [$other, $otherAccount, $otherCategory] = shortcutsSetup();

    shortcutsBook($user, $account, $category, 5);
    shortcutsBook($other, $otherAccount, $otherCategory, 6, ['description' => 'Secret', 'amount' => 99]);

    actingAs($user)
        ->getJson(route('transactions.shortcuts'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'shortcuts')
        ->assertJsonPath('shortcuts.0.description', 'Coffee')
        ->assertJsonPath('shortcuts.0.account_id', $account->id)
        ->assertJsonMissing(['description' => 'Secret']);

    actingAs($other)
        ->getJson(route('transactions.shortcuts'))
        ->assertJsonPath('shortcuts.0.description', 'Secret');
});

test('guests cannot read shortcuts', function () {
    getJson(route('transactions.shortcuts'))->assertUnauthorized();
});
