<?php

namespace App\Policies;

use App\Models\Statement;
use App\Models\User;

class StatementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Statement $statement): bool
    {
        return $user->id === $statement->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Statement $statement): bool
    {
        return $user->id === $statement->user_id;
    }

    public function delete(User $user, Statement $statement): bool
    {
        return $user->id === $statement->user_id;
    }
}
