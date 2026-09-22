<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Events\NotificationCreated;
use App\Events\NotificationRead;
use App\Mail\NotificationMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Realtime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Creates in-app notifications (broadcasting them live) and mirrors them by email, honouring
 * each user's notification preferences.
 */
class NotificationService
{
    /** Which preference toggle silences each notification type. */
    private const PREFERENCE_BY_TYPE = [
        'bookingRequested' => 'booking_updates',
        'bookingApproved' => 'booking_updates',
        'bookingRejected' => 'booking_updates',
        'paymentSubmitted' => 'booking_updates',
        'paymentVerified' => 'booking_updates',
        'boardingAvailable' => 'watchlist_alerts',
        'watchlistMatch' => 'watchlist_alerts',
        'searchMatch' => 'search_alerts',
        'demandSpike' => 'demand_alerts',
        'priceDrop' => 'price_alerts',
        'inquiryReply' => 'inquiry_alerts',
        'roommateMatchFound' => 'roommate_alerts',
        'suspiciousChatActivity' => 'safety_alerts',
        'safetyAlert' => 'safety_alerts',
        'reportSubmitted' => 'moderation_alerts',
        'complaintSubmitted' => 'moderation_alerts',
        'smartReminder' => 'booking_updates',
        'lowAvailability' => 'watchlist_alerts',
    ];

    public function preferencesFor(string $userId): NotificationPreference
    {
        return NotificationPreference::firstOrCreate(['user_id' => $userId]);
    }

    /** Is a real mail transport configured? (The default `log` mailer would only write to a file.) */
    public function canSendEmail(): bool
    {
        return match (config('mail.default')) {
            null, 'log' => false,
            'smtp' => filled(config('mail.mailers.smtp.host')) && filled(config('mail.mailers.smtp.username')),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  bool  $bypassPreference  for account-critical notices the user cannot opt out of
     */
    public function create(
        string $userId,
        NotificationType $type,
        string $title,
        string $message,
        NotificationChannel $channel = NotificationChannel::InApp,
        array $metadata = [],
        bool $bypassPreference = false,
    ): ?UserNotification {
        $preferences = $this->preferencesFor($userId);
        $user = User::find($userId, ['id', 'email', 'full_name', 'username']);

        $toggle = self::PREFERENCE_BY_TYPE[$type->value] ?? null;

        if (! $bypassPreference && $toggle && $preferences->{$toggle} === false) {
            return null;
        }

        $notification = null;

        if ($this->channelEnabled($channel, $preferences) && $channel === NotificationChannel::InApp) {
            $notification = UserNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'channel' => $channel,
                'title' => $title,
                'message' => $message,
                'is_read' => false,
                'metadata' => $metadata,
                'sent_at' => now(),
            ]);

            Realtime::dispatch(new NotificationCreated($notification));
        }

        if ($preferences->email_enabled && $user?->email && $this->canSendEmail()) {
            try {
                Mail::to($user->email)->send(new NotificationMail($title, $message, $user->displayName(), $metadata));
            } catch (Throwable $e) {
                Log::warning('Email notification failed: '.$e->getMessage());
            }
        }

        return $notification;
    }

    /**
     * @param  iterable<array<string, mixed>>  $entries  named arguments for {@see self::create()}
     * @return list<UserNotification>
     */
    public function createMany(iterable $entries): array
    {
        $created = [];

        foreach ($entries as $entry) {
            if ($notification = $this->create(...$entry)) {
                $created[] = $notification;
            }
        }

        return $created;
    }

    public function markAsRead(string $userId, string $notificationId): ?UserNotification
    {
        $notification = UserNotification::where('user_id', $userId)->find($notificationId);

        if (! $notification) {
            return null;
        }

        $notification->update(['is_read' => true, 'read_at' => now()]);

        Realtime::dispatch(new NotificationRead($userId, $notificationId));

        return $notification;
    }

    /**
     * Sends at most one reminder with this title per `$recentHours`, so page loads that
     * re-detect the same stale item do not spam the user.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function maybeCreateSmartReminder(
        string $userId,
        string $title,
        string $message,
        array $metadata = [],
        int $recentHours = 24,
    ): ?UserNotification {
        $existing = UserNotification::where('user_id', $userId)
            ->where('type', NotificationType::SmartReminder)
            ->where('title', $title)
            ->where('created_at', '>=', now()->subHours($recentHours))
            ->first();

        return $existing ?? $this->create(
            userId: $userId,
            type: NotificationType::SmartReminder,
            title: $title,
            message: $message,
            metadata: $metadata,
        );
    }

    private function channelEnabled(NotificationChannel $channel, NotificationPreference $preferences): bool
    {
        return match ($channel) {
            NotificationChannel::Email => $preferences->email_enabled,
            NotificationChannel::Sms => $preferences->sms_enabled,
            NotificationChannel::Push => $preferences->push_enabled,
            NotificationChannel::InApp => $preferences->in_app_enabled,
        };
    }
}
