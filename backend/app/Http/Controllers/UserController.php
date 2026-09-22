<?php

namespace App\Http\Controllers;

use App\Enums\BookingRequestStatus;
use App\Http\Requests\UpdateUserRequest;
use App\Models\BookingRequest;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Keys;
use App\Support\TokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json(User::latest()->get());
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = Keys::snake(array_filter($request->validated(), fn ($value) => $value !== null));

        $user->update($data);

        return response()->json($user->fresh());
    }

    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted'])->withCookie(TokenCookie::forget());
    }

    /** Toggles a listing in the user's saved list. */
    public function savePost(Request $request): JsonResponse
    {
        $postId = $request->validate(['postId' => ['required', 'string', 'exists:posts,id']])['postId'];

        $saved = SavedPost::where('user_id', $request->user()->id)->where('post_id', $postId)->first();

        if ($saved) {
            $saved->delete();

            return response()->json(['message' => 'Listing removed from saved list']);
        }

        SavedPost::create(['user_id' => $request->user()->id, 'post_id' => $postId]);

        return response()->json(['message' => 'Listing saved']);
    }

    /** Everything the dashboard needs in one round trip. */
    public function profilePosts(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();

        $userPosts = Post::where('owner_id', $user->id)->latest()->get();

        $savedPosts = SavedPost::with('post')->where('user_id', $user->id)->get()
            ->pluck('post')->filter()->values();

        $pendingBookings = BookingRequest::where('student_id', $user->id)
            ->whereIn('status', [
                BookingRequestStatus::Pending,
                BookingRequestStatus::Approved,
                BookingRequestStatus::PaymentPending,
            ])
            ->oldest()
            ->take(5)
            ->get();

        $stale = $pendingBookings->first(fn (BookingRequest $booking) => $booking->created_at <= now()->subDay());

        if ($stale) {
            $notifications->maybeCreateSmartReminder(
                userId: $user->id,
                title: 'Booking still pending',
                message: 'One of your bookings has been waiting for over 24 hours. Check for updates or follow up with the owner.',
                metadata: ['bookingRequestId' => $stale->id, 'postId' => $stale->post_id],
                recentHours: 12,
            );
        }

        return response()->json(compact('userPosts', 'savedPosts', 'pendingBookings'));
    }
}
