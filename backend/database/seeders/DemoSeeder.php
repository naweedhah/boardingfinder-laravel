<?php

namespace Database\Seeders;

use App\Models\BookingRequest;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\RoommatePreference;
use App\Models\SavedPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Watchlist;
use App\Support\Keys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data carried over from the Node/Prisma seed (`api/prisma/seed.js`): a student, two
 * owners, an admin, thirteen roommate-finder students, four Sri Lankan listings and the
 * bookings / inquiries / chats around them. Every account's password is `password123`.
 *
 * Re-running is safe: previous demo users (and everything cascading from them) are removed first.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    /** Maps the ids used in demo.json to the ULIDs created here. */
    private array $ids = [];

    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/demo.json'), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            User::whereIn('username', array_column($data['users'], 'username'))->delete();

            foreach ($data['users'] as $row) {
                $this->createUser($row);
            }

            foreach ($data['posts'] as $row) {
                $this->createPost($row);
            }

            foreach ($data['savedPost'] as $row) {
                SavedPost::create(Keys::snake($this->refs($row, ['userId', 'postId'])));
            }

            foreach ($data['watchlist'] as $row) {
                Watchlist::create(Keys::snake($this->refs($row, ['userId', 'postId'])));
            }

            foreach ($data['bookingRequest'] as $row) {
                BookingRequest::create(Keys::snake($this->refs($row, ['studentId', 'ownerId', 'postId'])));
            }

            foreach ($data['notification'] as $row) {
                UserNotification::create(Keys::snake($this->refs($row, ['userId'])));
            }

            foreach ($data['chat'] as $row) {
                $this->createChat($row);
            }

            foreach ($data['inquiry'] as $row) {
                Inquiry::create(Keys::snake($this->refs($row, ['studentId', 'ownerId', 'postId', 'chatId'])));
            }

            foreach ($data['message'] as $row) {
                Message::create(Keys::snake($this->refs($row, ['chatId', 'userId'])));
            }
        });

        $this->command?->info('Demo data seeded. Log in with e.g. demo_student / '.self::PASSWORD);
    }

    /** @param  array<string, mixed>  $row */
    private function createUser(array $row): void
    {
        $preference = $row['roommatePreference']['create'] ?? null;
        $notificationPreference = $row['notificationPreference']['create'] ?? null;
        unset($row['roommatePreference'], $row['notificationPreference']);

        $oldId = $row['id'];
        unset($row['id']);

        $user = User::create(Keys::snake($row) + ['password' => self::PASSWORD]);
        $this->ids[$oldId] = $user->id;

        if ($preference) {
            RoommatePreference::create(Keys::snake($preference) + ['user_id' => $user->id]);
        }

        if ($notificationPreference) {
            NotificationPreference::create(Keys::snake($notificationPreference) + ['user_id' => $user->id]);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function createPost(array $row): void
    {
        $detail = $row['postDetail']['create'];
        $oldId = $row['id'];
        unset($row['postDetail'], $row['id']);

        $post = Post::create(Keys::snake($this->refs($row, ['ownerId'])));
        $post->postDetail()->create(Keys::snake($detail));

        $this->ids[$oldId] = $post->id;
    }

    /** @param  array<string, mixed>  $row */
    private function createChat(array $row): void
    {
        $chat = Chat::create(['last_message' => $row['lastMessage']]);

        $seen = array_map(fn ($id) => $this->ids[$id], $row['seenBy']);

        foreach ($row['userIDs'] as $oldId) {
            $userId = $this->ids[$oldId];
            $chat->users()->attach($userId, ['seen' => in_array($userId, $seen, true)]);
        }

        $this->ids[$row['id']] = $chat->id;
    }

    /**
     * Replaces the demo.json ids in the given keys with the ids created in this run.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function refs(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                $row[$key] = $this->ids[$row[$key]];
            }
        }

        unset($row['id']);

        return $row;
    }
}
