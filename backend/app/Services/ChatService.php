<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Support\Push;
use App\Support\Realtime;

class ChatService
{
    public function __construct(
        private ScamDetector $scamDetector,
        private AlertNotifier $alerts,
    ) {}

    /**
     * Stores a message, updates the chat's preview/read state, warns the recipient if the text
     * looks like a scam, and pushes the message to them live.
     */
    public function send(Chat $chat, User $sender, string $text): Message
    {
        $flag = $this->scamDetector->detect($text);

        $message = $chat->messages()->create([
            'user_id' => $sender->id,
            'text' => $text,
            'scam_flag' => $flag,
        ]);

        $chat->update(['last_message' => $text]);
        $chat->markSeenOnlyBy($sender->id);

        $receiverId = $chat->users()->whereKeyNot($sender->id)->value('users.id');

        if ($receiverId) {
            $this->alerts->suspiciousChatActivity($receiverId, $sender->id, $chat->id, $message->id, $flag, $text);
            Realtime::dispatch(new MessageSent($message, $receiverId));
            Push::toUser($receiverId, 'New message from '.$sender->displayName(), $text, ['type' => 'chatMessage', 'chatId' => $chat->id]);
        }

        return $message;
    }
}
