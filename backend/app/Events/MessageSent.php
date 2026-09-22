<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Delivers a chat message to the other participant in real time (client event: `.message.new`). */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Message $message, public string $receiverId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->receiverId)];
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message->toArray();
    }
}
