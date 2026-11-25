<?php

namespace App\Jobs;

use App\Models\DevicePushToken;
use App\Services\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pushes one notification to every device the user is signed in on. Runs off the request path. */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ExpoPushClient $expo): void
    {
        $tokens = DevicePushToken::where('user_id', $this->userId)->pluck('token')->all();

        if ($tokens !== []) {
            $expo->send($tokens, $this->title, $this->body, $this->data);
        }
    }
}
