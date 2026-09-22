<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every user has one private channel that carries their notifications and incoming chat messages.
Broadcast::channel('users.{id}', fn (User $user, string $id) => $user->getKey() === $id);
