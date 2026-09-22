<?php

namespace App\Http\Controllers;

use App\Enums\BookingRequestStatus as Status;
use App\Enums\NotificationType;
use App\Models\BookingRequest;
use App\Models\Chat;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BookingController extends Controller
{
    private const RELATIONS = [
        'post:id,title,address,city,area,rent,images',
        'student:id,username,full_name,avatar,phone',
        'owner:id,username,full_name',
    ];

    public function __construct(private NotificationService $notifications) {}

    /** Owners see requests for their listings; everyone else sees the ones they sent. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            BookingRequest::with(self::RELATIONS)
                ->where($user->isBoardingOwner() ? 'owner_id' : 'student_id', $user->id)
                ->latest()->latest('id')
                ->get(),
        );
    }

    public function approve(Request $request, BookingRequest $booking): JsonResponse
    {
        Gate::authorize('respond', $booking);

        if ($booking->status !== Status::Pending) {
            return response()->json(['message' => 'Only pending bookings can be approved'], 400);
        }

        $booking->load(self::RELATIONS);
        $chat = Chat::openBetween($booking->student_id, $booking->owner_id, seenBy: [$booking->owner_id]);

        $booking->update(['status' => Status::Approved, 'approved_at' => now()]);

        $this->notifications->create(
            userId: $booking->student_id,
            type: NotificationType::BookingApproved,
            title: 'Booking request approved!',
            message: "Your booking for \"{$booking->post->title}\" has been approved. You can now chat with the owner to finalise details.",
            metadata: ['bookingRequestId' => $booking->id, 'postId' => $booking->post_id, 'chatId' => $chat->id],
        );

        return response()->json(['message' => 'Booking approved', 'booking' => $booking, 'chatId' => $chat->id]);
    }

    public function reject(Request $request, BookingRequest $booking): JsonResponse
    {
        Gate::authorize('respond', $booking);

        if ($booking->status !== Status::Pending) {
            return response()->json(['message' => 'Only pending bookings can be rejected'], 400);
        }

        $booking->load(self::RELATIONS);
        $booking->update(['status' => Status::Rejected, 'rejected_at' => now()]);

        $this->notifications->create(
            userId: $booking->student_id,
            type: NotificationType::BookingRejected,
            title: 'Booking request rejected',
            message: "Your booking request for \"{$booking->post->title}\" was not accepted by the owner.",
            metadata: ['bookingRequestId' => $booking->id, 'postId' => $booking->post_id],
        );

        return response()->json(['message' => 'Booking rejected', 'booking' => $booking]);
    }

    public function cancel(Request $request, BookingRequest $booking): JsonResponse
    {
        Gate::authorize('participate', $booking);

        $isStudent = $booking->student_id === $request->user()->id;

        // A student may back out until the owner confirms; an owner may still cancel a confirmed booking.
        $cancellable = $isStudent
            ? [Status::Pending, Status::Approved]
            : [Status::Pending, Status::Approved, Status::Confirmed];

        if (! in_array($booking->status, $cancellable, true)) {
            return response()->json(['message' => 'Cannot cancel at this stage'], 400);
        }

        $booking->load(self::RELATIONS);
        $booking->update(['status' => Status::Cancelled]);

        $actor = $isStudent ? $booking->student : $booking->owner;

        $this->notifications->create(
            userId: $isStudent ? $booking->owner_id : $booking->student_id,
            type: NotificationType::BookingRejected,
            title: 'Booking cancelled',
            message: "{$actor->displayName()} cancelled the booking for \"{$booking->post->title}\".",
            metadata: ['bookingRequestId' => $booking->id, 'postId' => $booking->post_id],
        );

        return response()->json(['message' => 'Booking cancelled', 'booking' => $booking]);
    }

    public function chat(BookingRequest $booking): JsonResponse
    {
        Gate::authorize('participate', $booking);

        return response()->json([
            'chatId' => Chat::openBetween($booking->student_id, $booking->owner_id, seenBy: [$booking->owner_id])->id,
        ]);
    }
}
