<?php

namespace App\Support;

use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues a mobile push for a user, if they have the app installed and have not turned push
 * off. Like {@see Realtime}, a failure here must never fail the request that triggered it.
 */
class Push
{
    /** @param  array<string, mixed>  $data */
    public static function toUser(string $userId, string $title, string $body, array $data = []): void
    {
        try {
            $optedOut = NotificationPreference::where('user_id', $userId)->where('push_enabled', false)->exists();

            if ($optedOut || ! DevicePushToken::where('user_id', $userId)->exists()) {
                return;
            }

            SendPushNotification::dispatch($userId, $title, $body, $data);
        } catch (Throwable $e) {
            Log::warning('Queueing push notification failed: '.$e->getMessage());
        }
    }
}
