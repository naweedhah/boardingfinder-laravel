<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Enums\ScamFlag;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * User-submitted reports and the admin moderation tools (overview, warn, suspend, resolve).
 * Everything except submitting/listing one's own reports sits behind the `role:admin` middleware.
 */
class ReportController extends Controller
{
    private const RELATIONS = ['reporter:id,username,full_name,avatar', 'post:id,title,address'];

    private const TARGET_USER = ['id', 'username', 'full_name', 'avatar', 'role', 'is_active', 'is_verified'];

    public function __construct(
        private NotificationService $notifications,
        private ReputationService $reputation,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targetId' => ['required', 'string'],
            'targetType' => ['nullable', Rule::enum(ReportTargetType::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'proofImage' => ['nullable', 'string', 'max:2048'],
            'postId' => ['nullable', 'string', 'exists:posts,id'],
        ], [
            'targetId.required' => 'Target and reason are required',
            'reason.required' => 'Target and reason are required',
            'targetType.enum' => 'Invalid report target type',
        ]);

        $targetType = $data['targetType'] ?? ReportTargetType::Message->value;

        $existing = Report::with(self::RELATIONS)
            ->where('reporter_id', $request->user()->id)
            ->where('target_id', $data['targetId'])
            ->where('target_type', $targetType)
            ->whereIn('status', [ReportStatus::Open, ReportStatus::UnderReview])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already reported this item', 'report' => $existing]);
        }

        $report = Report::create([
            'reporter_id' => $request->user()->id,
            'target_id' => $data['targetId'],
            'target_type' => $targetType,
            'reason' => trim($data['reason']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'proof_image' => filled($data['proofImage'] ?? null) ? trim($data['proofImage']) : null,
            'post_id' => $data['postId'] ?? null,
        ])->fresh(self::RELATIONS);

        return response()->json(['message' => 'Report submitted successfully', 'report' => $report], 201);
    }

    /** Development helper: files a fake report so the admin dashboard has something to show. */
    public function devSeed(Request $request): JsonResponse
    {
        if (app()->isProduction()) {
            return response()->json(['message' => 'Dev seed helpers are disabled in production'], 403);
        }

        $me = $request->user();
        $target = User::where('id', '!=', $me->id)->first() ?? $me;

        $report = Report::create([
            'reporter_id' => $me->id,
            'target_id' => $target->id,
            'target_type' => ReportTargetType::User,
            'reason' => 'Seeded moderation test for '.$target->displayName(),
            'description' => 'This is a development-only report created to test the admin moderation dashboard.',
            'post_id' => Post::value('id'),
        ])->fresh(self::RELATIONS);

        return response()->json([
            'message' => 'Seed report created successfully',
            'report' => $report,
            'note' => 'This helper is for local development only.',
        ], 201);
    }

    /** Admins see every report; everyone else sees the ones they filed. */
    public function index(Request $request): JsonResponse
    {
        $reports = Report::with(self::RELATIONS)
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('reporter_id', $request->user()->id))
            ->latest()->latest('id')
            ->get();

        $targetIds = $reports->mapWithKeys(fn (Report $report) => [$report->id => $this->reputation->reportedUserId($report)]);
        $targets = User::whereIn('id', $targetIds->filter()->unique())->get(self::TARGET_USER)->keyBy('id');

        return response()->json($reports->map(fn (Report $report) => [
            ...$report->toArray(),
            'targetUserId' => $targetIds[$report->id],
            'targetUser' => $targets->get($targetIds[$report->id]),
            'reputation' => $targetIds[$report->id] ? $this->reputation->summaryFor($targetIds[$report->id]) : null,
        ]));
    }

    public function adminOverview(): JsonResponse
    {
        $flagged = Message::where('scam_flag', '!=', ScamFlag::Safe);

        $flaggedMessages = (clone $flagged)->latest()->latest('id')->take(6)->get();
        $senders = User::whereIn('id', $flaggedMessages->pluck('user_id')->unique())
            ->get(['id', 'username', 'full_name', 'avatar'])->keyBy('id');
        $chats = Chat::with(['users:id', 'inquiry.post:id,title'])
            ->whereIn('id', $flaggedMessages->pluck('chat_id')->unique())->get()->keyBy('id');

        return response()->json([
            'summary' => [
                'totalReports' => Report::count(),
                'openReports' => Report::where('status', ReportStatus::Open)->count(),
                'underReviewReports' => Report::where('status', ReportStatus::UnderReview)->count(),
                'resolvedReports' => Report::where('status', ReportStatus::Resolved)->count(),
                'totalInquiries' => Inquiry::count(),
                'pendingInquiries' => Inquiry::where('status', InquiryStatus::Pending)->count(),
                'acceptedInquiries' => Inquiry::where('status', InquiryStatus::Accepted)->count(),
                'totalFlaggedMessages' => (clone $flagged)->count(),
                'dangerMessages' => Message::where('scam_flag', ScamFlag::Danger)->count(),
            ],
            'recentInquiries' => Inquiry::with([
                'post:id,title,city,area',
                'student:id,username,full_name,avatar',
                'owner:id,username,full_name,avatar',
                'chat:id,last_message,created_at',
            ])->latest()->latest('id')->take(6)->get(),
            'suspiciousMessages' => $flaggedMessages->map(fn (Message $message) => [
                ...$message->toArray(),
                'sender' => $senders->get($message->user_id),
                'chat' => ($chat = $chats->get($message->chat_id)) ? [
                    'id' => $chat->id,
                    'userIDs' => $chat->users->pluck('id')->values(),
                    'lastMessage' => $chat->last_message,
                    'inquiry' => $chat->inquiry ? ['id' => $chat->inquiry->id, 'post' => $chat->inquiry->post?->only(['id', 'title'])] : null,
                ] : null,
            ])->values(),
        ]);
    }

    public function warn(Request $request): JsonResponse
    {
        $reportId = $request->validate(['reportId' => ['required', 'string']], ['reportId.required' => 'Report id is required'])['reportId'];

        $report = Report::with(self::RELATIONS)->findOrFail($reportId);
        $targetUserId = $this->reputation->reportedUserId($report);

        if (! $targetUserId) {
            return response()->json(['message' => 'Could not determine which user to warn'], 400);
        }

        $this->notifications->create(
            userId: $targetUserId,
            type: NotificationType::SafetyAlert,
            title: 'Account warning issued',
            message: "An admin reviewed a report and issued a warning related to: {$report->reason}.",
            metadata: ['reportId' => $report->id, 'targetType' => $report->target_type->value, 'targetId' => $report->target_id],
        );

        $report->update(['status' => ReportStatus::UnderReview, 'admin_notes' => 'Warning sent to the reported user.']);

        return response()->json(['message' => 'Warning issued successfully', 'report' => $report, 'targetUserId' => $targetUserId]);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([ReportStatus::Resolved->value, ReportStatus::Dismissed->value, ReportStatus::UnderReview->value])],
            'adminNotes' => ['nullable', 'string', 'max:5000'],
        ], ['status.in' => 'Invalid report status']);

        $status = ReportStatus::from($data['status'] ?? 'resolved');
        $report = Report::with(self::RELATIONS)->findOrFail($id);

        $report->update([
            'status' => $status,
            'admin_notes' => filled($data['adminNotes'] ?? null) ? trim($data['adminNotes']) : $report->admin_notes,
            ...($status === ReportStatus::UnderReview ? [] : ['resolved_at' => now()]),
        ]);

        return response()->json(['message' => 'Report updated successfully', 'report' => $report]);
    }

    /** Suspends (or restores) the account behind a report. Suspended users are locked out at once. */
    public function setUserState(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reportId' => ['required', 'string'],
            'isActive' => ['required', 'boolean:strict'],
            'adminNotes' => ['nullable', 'string', 'max:5000'],
        ], ['reportId.required' => 'Report id and active state are required', 'isActive.*' => 'Report id and active state are required']);

        $report = Report::with(self::RELATIONS)->findOrFail($data['reportId']);
        $targetUserId = $this->reputation->reportedUserId($report);
        $isActive = (bool) $data['isActive'];

        if (! $targetUserId) {
            return response()->json(['message' => 'Could not determine which user to update'], 400);
        }

        $user = User::findOrFail($targetUserId);
        $user->update(['is_active' => $isActive]);

        if (! $isActive) {
            $user->tokens()->delete();
        }

        $report->update([
            'status' => $isActive ? ReportStatus::UnderReview : ReportStatus::Resolved,
            'admin_notes' => filled($data['adminNotes'] ?? null)
                ? trim($data['adminNotes'])
                : ($isActive ? 'User account restored by admin review.' : 'User account suspended by admin review.'),
            ...($isActive ? [] : ['resolved_at' => now()]),
        ]);

        $this->notifications->create(
            userId: $targetUserId,
            type: NotificationType::SafetyAlert,
            title: $isActive ? 'Account access restored' : 'Account suspended',
            message: $isActive
                ? 'An admin reviewed your case and restored account access.'
                : "An admin reviewed a report and suspended your account related to: {$report->reason}.",
            metadata: [
                'reportId' => $report->id,
                'targetType' => $report->target_type->value,
                'targetId' => $report->target_id,
                'isActive' => $isActive,
            ],
            bypassPreference: true,
        );

        return response()->json([
            'message' => $isActive ? 'User account restored' : 'User account suspended',
            'report' => $report,
            'targetUser' => collect($user->fresh()->toArray())->only(['id', 'username', 'fullName', 'avatar', 'role', 'isActive', 'isVerified']),
        ]);
    }
}
