<?php

namespace App\Policies;

use App\Models\BookingRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingRequestPolicy
{
    /** Approve / reject: only the listing's owner. */
    public function respond(User $user, BookingRequest $booking): Response
    {
        return $booking->owner_id === $user->id ? Response::allow() : Response::deny('Not authorized');
    }

    /** Cancel / open chat: either party. */
    public function participate(User $user, BookingRequest $booking): Response
    {
        return in_array($user->id, [$booking->student_id, $booking->owner_id], true)
            ? Response::allow()
            : Response::deny('Not authorized');
    }
}
