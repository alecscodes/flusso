<?php

use App\Mcp\Servers\FlussoServer;
use App\Mcp\Tools;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

use function Pest\Laravel\actingAs;

/**
 * Lazy loading is prohibited outside production, and it only trips over lists of
 * several models, so every list below holds more than one row.
 */
beforeEach(function () {
    $this->travelTo('2026-05-10 12:00:00');
    expect(Model::preventsLazyLoading())->toBeTrue();

    $this->user = User::factory()->create();
    $this->eur = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $this->ron = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'RON', 'balance' => 500]);
    $this->food = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);
    Category::factory()->create(['user_id' => $this->user->id, 'type' => 'income']);

    foreach (range(1, 3) as $day) {
        Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'type' => 'expense', 'date' => "2026-05-0{$day}"]);
    }

    Transaction::factory()->transfer($this->ron, 50)->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'date' => '2026-05-04']);
    Transaction::factory()->transfer($this->eur, 10)->create(['user_id' => $this->user->id, 'account_id' => $this->ron->id, 'date' => '2026-05-05']);

    plannedPayment($this->user, ['name' => 'Rent', 'start_date' => '2026-04-20'], $this->eur);
    plannedPayment($this->user, ['name' => 'Phone', 'start_date' => '2026-05-12', 'installments' => 3], $this->ron);
    plannedPayment($this->user, ['name' => 'Once', 'start_date' => '2026-06-01', 'installments' => 1], $this->eur);
});

test('every page renders lists of several rows without lazy loading', function () {
    $rule = $this->user->recurringPayments()->first();

    foreach ([
        route('dashboard'),
        route('accounts.index'),
        route('accounts.show', $this->eur),
        route('categories.index'),
        route('transactions.index'),
        route('transactions.index', ['date_start' => '2026-05-01', 'date_end' => '2026-05-31']),
        route('payments.index'),
        route('planned-payments.index'),
        route('planned-payments.show', $rule),
    ] as $url) {
        actingAs($this->user)->get($url)->assertSuccessful();
    }
});

test('the read-only tools run without lazy loading', function () {
    foreach ([
        [Tools\GetOverview::class, []],
        [Tools\ListAccounts::class, []],
        [Tools\ListCategories::class, []],
        [Tools\SearchTransactions::class, []],
        [Tools\ListPayments::class, ['scope' => 'overdue']],
        [Tools\ListPayments::class, ['scope' => 'upcoming', 'days' => 60]],
        [Tools\ListPayments::class, ['scope' => 'range', 'date_from' => '2026-04-01', 'date_to' => '2026-07-01']],
        [Tools\ListPlannedPayments::class, []],
    ] as [$tool, $arguments]) {
        FlussoServer::actingAs($this->user)->tool($tool, $arguments)->assertOk();
    }
});
