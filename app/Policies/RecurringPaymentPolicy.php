<?php

namespace App\Policies;

use App\Models\RecurringPayment;
use App\Models\User;

class RecurringPaymentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RecurringPayment $recurringPayment): bool
    {
        return $user->id === $recurringPayment->user_id;
    }
}
