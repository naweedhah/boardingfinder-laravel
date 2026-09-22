<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PostPolicy
{
    public function update(User $user, Post $post): Response
    {
        return $post->owner_id === $user->id ? Response::allow() : Response::deny('Not Authorized!');
    }

    public function delete(User $user, Post $post): Response
    {
        return $this->update($user, $post);
    }
}
