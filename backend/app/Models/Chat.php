<?php

namespace App\Models;

use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['last_message'])]
class Chat extends Model
{
    use HasFactory, HasUlids, SerializesCamelCase;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('seen');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /** The most recent inquiry that opened (or reused) this chat; shown in the admin overview. */
    public function inquiry(): HasOne
    {
        return $this->hasOne(Inquiry::class)->latestOfMany();
    }

    /** Chats the given user takes part in. */
    #[Scope]
    protected function forUser(Builder $query, string $userId): void
    {
        $query->whereHas('users', fn (Builder $q) => $q->whereKey($userId));
    }

    /** The chat shared by two specific users, if any. */
    #[Scope]
    protected function between(Builder $query, string $userA, string $userB): void
    {
        $query->forUser($userA)->forUser($userB);
    }

    /**
     * Finds the chat between two users or opens a new one. `$seenBy` lists who has already
     * "seen" the (still empty) conversation.
     *
     * @param  list<string>  $seenBy
     */
    public static function openBetween(string $userA, string $userB, array $seenBy = []): self
    {
        $chat = static::query()->between($userA, $userB)->first();

        if ($chat) {
            return $chat;
        }

        $chat = static::create();
        $chat->users()->attach([
            $userA => ['seen' => in_array($userA, $seenBy, true)],
            $userB => ['seen' => in_array($userB, $seenBy, true)],
        ]);

        return $chat;
    }

    /** Marks the chat as seen by exactly the given user (everyone else has unread messages). */
    public function markSeenOnlyBy(string $userId): void
    {
        foreach ($this->users()->pluck('users.id') as $id) {
            $this->users()->updateExistingPivot($id, ['seen' => $id === $userId]);
        }
    }

    public function hasParticipant(string $userId): bool
    {
        return $this->users()->whereKey($userId)->exists();
    }
}
