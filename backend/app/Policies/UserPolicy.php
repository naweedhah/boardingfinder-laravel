<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->isAdmin() ? Response::allow() : Response::deny('Admin access required');
    }

    public function update(User $user, User $target): Response
    {
        return $user->is($target) ? Response::allow() : Response::deny('Not Authorized!');
    }

    public function delete(User $user, User $target): Response
    {
        return $this->update($user, $target);
    }
}
