<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'reset_date'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

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

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function recurringPayments()
    {
        return $this->hasMany(RecurringPayment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getFinancialPeriod(): array
    {
        $resetDate = $this->reset_date;

        if ($resetDate === null) {
            return [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ];
        }

        $today = now();
        $currentDay = $today->day;

        if ($currentDay >= $resetDate) {
            $start = $today->copy()->day($resetDate)->startOfDay();
            $end = $today->copy()->addMonth()->day($resetDate)->subDay()->endOfDay();
        } else {
            $start = $today->copy()->subMonth()->day($resetDate)->startOfDay();
            $end = $today->copy()->day($resetDate)->subDay()->endOfDay();
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}
