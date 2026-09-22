<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Enums\InquiryType;
use App\Enums\NotificationType;
use App\Http\Resources\ChatResource;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Models\Post;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A student's question about a listing ("can I view it?" / "I want to book"). The owner accepts
 * (which opens a chat) or rejects; the student may cancel.
 */
class InquiryController extends Controller
{
    private const RELATIONS = [
        'post:id,title,address,city,area,owner_id',
        'student:id,username,full_name,avatar',
        'owner:id,username,full_name,avatar',
        'chat:id,last_message,created_at',
    ];

    public function __construct(private NotificationService $notifications) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'postId' => ['required', 'string'],
            'type' => ['nullable', Rule::enum(InquiryType::class)],
        ], ['postId.required' => 'Post id is required', 'type.enum' => 'Invalid inquiry type']);

        $user = $request->user();

        if (! $user->isStudent()) {
            return response()->json(['message' => 'Only students can create inquiries'], 403);
        }

        $post = Post::findOrFail($data['postId']);

        if ($post->owner_id === $user->id) {
            return response()->json(['message' => 'You cannot create an inquiry for your own listing'], 400);
        }

        $existing = Inquiry::with(self::RELATIONS)
            ->where('post_id', $post->id)
            ->where('student_id', $user->id)
            ->whereIn('status', [InquiryStatus::Pending, InquiryStatus::Accepted])
            ->latest()->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have an active inquiry for this listing',
                'inquiry' => $existing,
            ]);
        }

        $inquiry = Inquiry::create([
            'post_id' => $post->id,
            'owner_id' => $post->owner_id,
            'student_id' => $user->id,
            'type' => $data['type'] ?? InquiryType::View->value,
        ])->fresh(self::RELATIONS);

        $this->notifications->create(
            userId: $post->owner_id,
            type: NotificationType::BookingRequested,
            title: 'New inquiry received',
            message: "{$user->displayName()} sent an inquiry about \"{$post->title}\".",
            metadata: ['inquiryId' => $inquiry->id, 'postId' => $post->id, 'studentId' => $user->id],
        );

        $this->notifications->create(
            userId: $user->id,
            type: NotificationType::InquiryReply,
            title: 'Inquiry sent successfully',
            message: "Your inquiry for \"{$post->title}\" was sent to the owner. We'll let you know when they reply.",
            metadata: ['inquiryId' => $inquiry->id, 'postId' => $post->id, 'status' => 'pending'],
        );

        return response()->json(['message' => 'Inquiry created successfully', 'inquiry' => $inquiry], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isBoardingOwner()) {
            $this->remindAboutStaleInquiries($user->id);
        }

        $inquiries = Inquiry::with(self::RELATIONS)
            ->when($user->isBoardingOwner(), fn ($q) => $q->where('owner_id', $user->id))
            ->when($user->isStudent(), fn ($q) => $q->where('student_id', $user->id))
            ->latest()->latest('id')
            ->get();

        return response()->json($inquiries);
    }

    /** Reject (owner) or cancel (student). Accepting has its own endpoint because it opens a chat. */
    public function updateStatus(Request $request, Inquiry $inquiry): JsonResponse
    {
        $status = $request->validate([
            'status' => ['required', Rule::in([InquiryStatus::Pending->value, InquiryStatus::Rejected->value, InquiryStatus::Cancelled->value])],
        ], ['status.*' => 'Invalid inquiry status'])['status'];

        $user = $request->user();
        $isOwner = $inquiry->owner_id === $user->id;
        $isStudent = $inquiry->student_id === $user->id;
        $isAdmin = $user->isAdmin();

        if (! ($isOwner || $isStudent || $isAdmin)) {
            return response()->json(['message' => 'Not authorized to update this inquiry'], 403);
        }

        if ($status === 'rejected' && ! ($isOwner || $isAdmin)) {
            return response()->json(['message' => 'Only the owner can reject inquiries'], 403);
        }

        if ($status === 'cancelled' && ! ($isStudent || $isAdmin)) {
            return response()->json(['message' => 'Only the student can cancel inquiries'], 403);
        }

        $inquiry->load(self::RELATIONS);
        $inquiry->update(['status' => $status]);

        $title = $inquiry->post?->title;

        if ($status === 'rejected') {
            $this->notifications->create(
                userId: $inquiry->student_id,
                type: NotificationType::InquiryReply,
                title: 'Your inquiry was updated',
                message: "{$inquiry->owner->displayName()} rejected your inquiry for ".($title ?? 'the listing').'.',
                metadata: ['inquiryId' => $inquiry->id, 'postId' => $inquiry->post_id, 'status' => $status],
            );
        }

        if ($status === 'cancelled') {
            $this->notifications->create(
                userId: $inquiry->owner_id,
                type: NotificationType::InquiryReply,
                title: 'An inquiry was cancelled',
                message: "{$inquiry->student->displayName()} cancelled their inquiry for ".($title ?? 'your listing').'.',
                metadata: ['inquiryId' => $inquiry->id, 'postId' => $inquiry->post_id, 'status' => $status],
            );
        }

        return response()->json(['message' => 'Inquiry updated successfully', 'inquiry' => $inquiry]);
    }

    public function accept(Request $request, Inquiry $inquiry): JsonResponse
    {
        $user = $request->user();

        if ($inquiry->owner_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Only the owner can accept this inquiry'], 403);
        }

        $chat = Chat::openBetween($inquiry->student_id, $inquiry->owner_id, seenBy: [$user->id]);

        $inquiry->update(['status' => InquiryStatus::Accepted, 'chat_id' => $chat->id]);
        $inquiry->load(self::RELATIONS);

        $this->notifications->create(
            userId: $inquiry->student_id,
            type: NotificationType::InquiryReply,
            title: 'Inquiry accepted',
            message: "{$inquiry->owner->displayName()} accepted your inquiry and opened a chat.",
            metadata: [
                'inquiryId' => $inquiry->id,
                'chatId' => $chat->id,
                'postId' => $inquiry->post_id,
                'status' => 'accepted',
            ],
        );

        return response()->json([
            'message' => 'Inquiry accepted and chat opened',
            'inquiry' => $inquiry,
            'chat' => new ChatResource($chat->load('users:id,username,avatar')),
        ]);
    }

    /** Nudge owners about students who have waited over a day, at most every 12 hours. */
    private function remindAboutStaleInquiries(string $ownerId): void
    {
        $stale = Inquiry::where('owner_id', $ownerId)
            ->where('status', InquiryStatus::Pending)
            ->where('created_at', '<=', now()->subDay())
            ->oldest()
            ->first();

        if ($stale) {
            $this->notifications->maybeCreateSmartReminder(
                userId: $ownerId,
                title: 'You have unanswered inquiries',
                message: 'One or more student inquiries have been waiting for over 24 hours.',
                metadata: ['inquiryId' => $stale->id],
                recentHours: 12,
            );
        }
    }
}
