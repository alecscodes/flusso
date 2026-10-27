<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'reset_date'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<RecurringPayment, $this> */
    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The current financial period, from the reset day to the day before the next
     * one. The reset day is clamped to the month's length (31 -> Feb 28/29).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function getFinancialPeriod(): array
    {
        $month = now()->startOfMonth();

        if ($this->reset_date === null) {
            return ['start' => $month, 'end' => $month->endOfMonth()];
        }

        $resetDayIn = fn (CarbonImmutable $m): CarbonImmutable => $m->addDays(min((int) $this->reset_date, $m->daysInMonth) - 1);

        $start = $resetDayIn($month);

        if (now()->lt($start)) {
            $start = $resetDayIn($month->subMonth());
            $nextStart = $resetDayIn($month);
        } else {
            $nextStart = $resetDayIn($month->addMonth());
        }

        return ['start' => $start, 'end' => $nextStart->subDay()->endOfDay()];
    }
}
