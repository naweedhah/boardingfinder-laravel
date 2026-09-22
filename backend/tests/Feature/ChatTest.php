<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\NotificationCreated;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatTest extends TestCase
{
    private function chatBetween(User $a, User $b, array $seenBy = []): Chat
    {
        return Chat::openBetween($a->id, $b->id, $seenBy);
    }

    #[Test]
    public function starting_a_chat_twice_returns_the_same_conversation(): void
    {
        $me = $this->signIn();
        $owner = User::factory()->owner()->create();

        $first = $this->postJson('/api/chats', ['receiverId' => $owner->id])
            ->assertOk()
            ->assertJsonPath('userIDs', fn ($ids) => count($ids) === 2 && in_array($me->id, $ids) && in_array($owner->id, $ids))
            ->assertJsonPath('seenBy', [$me->id])
            ->json('id');

        $this->postJson('/api/chats', ['receiverId' => $owner->id])->assertOk()->assertJsonPath('id', $first);
        $this->assertSame(1, Chat::count());
    }

    #[Test]
    public function you_cannot_chat_with_yourself_or_a_stranger_id(): void
    {
        $me = $this->signIn();

        $this->postJson('/api/chats', ['receiverId' => $me->id])
            ->assertStatus(422)->assertJson(['message' => 'You cannot start a chat with yourself.']);
        $this->postJson('/api/chats', ['receiverId' => 'missing'])->assertStatus(422);
    }

    #[Test]
    public function the_chat_list_shows_the_other_participant_and_who_has_read_it(): void
    {
        $me = $this->signIn();
        $owner = User::factory()->owner()->create(['username' => 'kumari_homes']);
        $chat = $this->chatBetween($me, $owner, seenBy: [$owner->id]);
        $chat->update(['last_message' => 'See you Saturday']);
        $this->chatBetween(User::factory()->create(), User::factory()->create()); // not mine

        $this->getJson('/api/chats')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $chat->id)
            ->assertJsonPath('0.receiver.username', 'kumari_homes')
            ->assertJsonPath('0.receiver.id', $owner->id)
            ->assertJsonPath('0.lastMessage', 'See you Saturday')
            ->assertJsonPath('0.seenBy', [$owner->id]);
    }

    #[Test]
    public function opening_a_chat_returns_messages_in_order_and_marks_it_seen(): void
    {
        $me = $this->signIn();
        $owner = User::factory()->owner()->create();
        $chat = $this->chatBetween($me, $owner);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $owner->id, 'text' => 'first']);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $me->id, 'text' => 'second']);

        $this->getJson("/api/chats/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.text', 'first')
            ->assertJsonPath('messages.1.text', 'second')
            ->assertJsonPath('messages.1.userId', $me->id);

        $this->assertTrue((bool) $chat->users()->whereKey($me->id)->first()->pivot->seen);
        $this->assertFalse((bool) $chat->users()->whereKey($owner->id)->first()->pivot->seen);
    }

    #[Test]
    public function outsiders_cannot_open_or_post_to_a_chat(): void
    {
        $chat = $this->chatBetween(User::factory()->create(), User::factory()->create());
        $this->signIn();

        $this->getJson("/api/chats/{$chat->id}")->assertNotFound()->assertJson(['message' => 'Chat not found!']);
        $this->putJson("/api/chats/read/{$chat->id}")->assertNotFound();
        $this->postJson("/api/messages/{$chat->id}", ['text' => 'hi'])->assertNotFound();
    }

    #[Test]
    public function sending_a_message_updates_the_chat_and_pushes_it_to_the_recipient(): void
    {
        Event::fake([MessageSent::class, NotificationCreated::class]);

        $me = $this->signIn();
        $owner = User::factory()->owner()->create();
        $chat = $this->chatBetween($me, $owner);

        $this->postJson("/api/messages/{$chat->id}", ['text' => '  Is Wi-Fi included?  '])
            ->assertOk()
            ->assertJsonPath('text', 'Is Wi-Fi included?')
            ->assertJsonPath('userId', $me->id)
            ->assertJsonPath('chatId', $chat->id)
            ->assertJsonPath('scamFlag', 'safe');

        $chat->refresh();
        $this->assertSame('Is Wi-Fi included?', $chat->last_message);
        $this->assertTrue((bool) $chat->users()->whereKey($me->id)->first()->pivot->seen);
        $this->assertFalse((bool) $chat->users()->whereKey($owner->id)->first()->pivot->seen);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->receiverId === $owner->id
            && $e->broadcastAs() === 'message.new'
            && $e->broadcastOn()[0]->name === "private-users.{$owner->id}");
        Event::assertNotDispatched(NotificationCreated::class); // nothing suspicious about it
    }

    #[Test]
    public function risky_messages_are_flagged_and_warn_the_recipient(): void
    {
        $me = $this->signIn();
        $owner = User::factory()->owner()->create();
        $chat = $this->chatBetween($me, $owner);

        $this->postJson("/api/messages/{$chat->id}", ['text' => 'Please send money as an advance payment'])
            ->assertOk()->assertJsonPath('scamFlag', 'danger');
        $this->postJson("/api/messages/{$chat->id}", ['text' => 'URGENT reply needed'])
            ->assertOk()->assertJsonPath('scamFlag', 'warning');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'Suspicious activity detected in chat']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'Potentially risky message detected']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $me->id]);
    }

    #[Test]
    public function empty_messages_are_rejected(): void
    {
        $me = $this->signIn();
        $chat = $this->chatBetween($me, User::factory()->create());

        $this->postJson("/api/messages/{$chat->id}", ['text' => ''])
            ->assertStatus(422)->assertJson(['message' => 'Message text is required']);
    }

    #[Test]
    public function marking_read_leaves_only_the_reader_in_seen_by(): void
    {
        $me = $this->signIn();
        $other = User::factory()->create();
        $chat = $this->chatBetween($me, $other, seenBy: [$other->id]);

        $this->putJson("/api/chats/read/{$chat->id}")->assertOk()->assertJsonPath('seenBy', [$me->id]);
    }

    #[Test]
    public function the_support_chat_endpoints_let_admins_read_any_conversation(): void
    {
        $student = User::factory()->create();
        $owner = User::factory()->owner()->create();
        $chat = $this->chatBetween($student, $owner);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $student->id, 'text' => 'hello']);

        $this->signIn();
        $this->getJson("/api/sakith-chat/{$chat->id}")->assertNotFound();

        $this->signIn($student);
        $this->getJson("/api/sakith-chat/{$chat->id}")->assertOk()->assertJsonPath('0.text', 'hello');
        $this->postJson('/api/sakith-chat/send', ['chatId' => $chat->id, 'text' => 'again'])
            ->assertCreated()->assertJsonPath('text', 'again');

        $this->signIn(User::factory()->admin()->create());
        $this->getJson("/api/sakith-chat/{$chat->id}")->assertOk()->assertJsonCount(2);
    }
}
