<?php

namespace App\Services;

use App\Models\DevicePushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through Expo's push service, which forwards them to APNs (iOS)
 * and FCM (Android), so the API does not hold Apple or Google credentials itself.
 *
 * @see https://docs.expo.dev/push-notifications/sending-notifications/
 */
class ExpoPushClient
{
    /** Expo accepts at most 100 messages per request. */
    public const BATCH_SIZE = 100;

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data  delivered to the app for deep linking
     * @return int number of tickets Expo accepted
     */
    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        $accepted = 0;

        foreach (array_chunk($tokens, self::BATCH_SIZE) as $batch) {
            $messages = array_map(fn (string $to) => [
                'to' => $to,
                'title' => $title,
                'body' => mb_strimwidth($body, 0, 180, '…'),
                'data' => (object) $data,
                'sound' => 'default',
                'channelId' => 'default',
            ], $batch);

            $response = Http::withToken((string) config('services.expo.access_token'))
                ->acceptJson()
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->post((string) config('services.expo.push_url'), $messages);

            if (! $response->successful()) {
                Log::warning('Expo push request failed', ['status' => $response->status()]);

                continue;
            }

            // Tickets come back in the same order as the messages.
            foreach ($response->json('data', []) as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'ok') {
                    $accepted++;
                } elseif (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    // The app was uninstalled or the token rotated; stop sending to it.
                    DevicePushToken::where('token', $batch[$i] ?? null)->delete();
                }
            }
        }

        return $accepted;
    }
}
