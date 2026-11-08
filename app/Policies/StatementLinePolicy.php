<?php

namespace App\Policies;

use App\Models\StatementLine;
use App\Models\User;

class StatementLinePolicy
{
    public function update(User $user, StatementLine $line): bool
    {
        return $user->id === $line->user_id;
    }
}
