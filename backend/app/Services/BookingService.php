<?php

namespace App\Services;

use App\Enums\BoardingStatus;
use App\Enums\BookingRequestStatus;
use App\Enums\NotificationType;
use App\Models\BookingRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private NotificationService $notifications,
        private AlertNotifier $alerts,
    ) {}

    public function activeRequestCount(Post $post): int
    {
        return $post->bookingRequests()->whereIn('status', BookingRequestStatus::activeValues())->count();
    }

    public function remainingSlots(Post $post): int
    {
        return max(0, $post->capacity - $this->activeRequestCount($post));
    }

    /**
     * Creates a booking request unless the student already has a live one for the listing.
     * The slot check runs inside a transaction with the listing row locked so two students
     * cannot claim the last slot at the same time.
     *
     * @param  array<string, string|null>  $applicant  applicantName / applicantPhone / applicantAddress / applicantNIC
     * @return array{request: BookingRequest, created: bool}
     *
     * @throws BookingException when the listing cannot take another request
     */
    public function request(User $student, Post $post, array $applicant): array
    {
        $result = DB::transaction(function () use ($student, $post, $applicant) {
            $locked = Post::whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BoardingStatus::Available) {
                throw new BookingException('This boarding is not available right now');
            }

            $existing = BookingRequest::where('post_id', $locked->id)
                ->where('student_id', $student->id)
                ->whereIn('status', BookingRequestStatus::activeValues())
                ->latest()
                ->first();

            // Checked before slots: re-sending must stay idempotent even when the listing is now full.
            if ($existing) {
                return ['request' => $existing, 'created' => false, 'remaining' => null];
            }

            $remaining = $this->remainingSlots($locked);

            if ($remaining < 1) {
                throw new BookingException('No remaining slots available');
            }

            $request = BookingRequest::create([
                'student_id' => $student->id,
                'owner_id' => $locked->owner_id,
                'post_id' => $locked->id,
                'status' => BookingRequestStatus::Pending,
                'applicant_name' => $applicant['applicantName'] ?? null,
                'applicant_phone' => $applicant['applicantPhone'] ?? null,
                'applicant_address' => $applicant['applicantAddress'] ?? null,
                'applicant_nic' => $applicant['applicantNIC'] ?? null,
            ]);

            return ['request' => $request, 'created' => true, 'remaining' => $remaining - 1];
        });

        if ($result['created']) {
            $this->announce($student, $post, $result['request'], $result['remaining']);
        }

        return ['request' => $result['request'], 'created' => $result['created']];
    }

    private function announce(User $student, Post $post, BookingRequest $request, int $remainingAfter): void
    {
        $this->notifications->create(
            userId: $post->owner_id,
            type: NotificationType::BookingRequested,
            title: 'New booking request received',
            message: "{$student->displayName()} requested to book \"{$post->title}\".",
            metadata: ['bookingRequestId' => $request->id, 'postId' => $post->id, 'studentId' => $student->id],
        );

        $this->notifications->create(
            userId: $student->id,
            type: NotificationType::BookingRequested,
            title: 'Booking request sent',
            message: "Your booking request for \"{$post->title}\" has been sent to the owner.",
            metadata: ['bookingRequestId' => $request->id, 'postId' => $post->id, 'ownerId' => $post->owner_id],
        );

        $this->alerts->lowAvailability($post, $remainingAfter);

        if ($remainingAfter < 1) {
            $this->notifications->maybeCreateSmartReminder(
                userId: $post->owner_id,
                title: 'A listing just filled up',
                message: "\"{$post->title}\" has no remaining slots. Review pending bookings and availability.",
                metadata: ['postId' => $post->id, 'remainingSlots' => $remainingAfter],
                recentHours: 6,
            );
        }
    }
}
