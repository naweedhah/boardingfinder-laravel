<?php

namespace App\Http\Controllers;

use App\Mail\NotificationMail;
use App\Models\NotificationPreference;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** Number of unread notifications (drives the navbar badge). */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(
            UserNotification::where('user_id', $request->user()->id)->where('is_read', false)->count(),
        );
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'unseenCount' => UserNotification::where('user_id', $userId)->where('is_read', false)->count(),
            'notifications' => UserNotification::where('user_id', $userId)->latest()->latest('id')->take(20)->get(),
            'preferences' => $this->notifications->preferencesFor($userId),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notifications->markAsRead($request->user()->id, $id);

        return $notification
            ? response()->json($notification)
            : response()->json(['message' => 'Notification not found!'], 404);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json($this->notifications->preferencesFor($request->user()->id));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $preferences = $this->notifications->preferencesFor($request->user()->id);

        $changes = [];
        foreach (NotificationPreference::TOGGLES as $key => $column) {
            if ($request->has($key)) {
                $changes[$column] = $request->boolean($key);
            }
        }

        $preferences->update($changes);

        return response()->json($preferences);
    }

    /** Development helper for checking mail configuration from the profile page. */
    public function testEmail(Request $request): JsonResponse
    {
        if (app()->isProduction()) {
            return response()->json(['message' => 'Test email helpers are disabled in production.'], 403);
        }

        $user = $request->user();

        if (! $user->email) {
            return response()->json(['message' => 'Your account does not have an email address.'], 400);
        }

        if (! $this->notifications->canSendEmail()) {
            return response()->json([
                'message' => 'Email is not configured yet. Set MAIL_MAILER=smtp and the MAIL_* values in backend/.env and restart the server.',
            ], 400);
        }

        try {
            Mail::to($user->email)->sendNow(new NotificationMail(
                title: 'Test email from BoardingFinder',
                body: 'This is a development test email to confirm that your mail notification setup is working.',
                recipientName: $user->displayName(),
            ));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage() ?: 'Failed to send test email.', 'code' => $e->getCode() ?: null], 500);
        }

        return response()->json(['message' => "Test email sent to {$user->email}"]);
    }
}
