<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ScamFlag;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\Watchlist;
use Illuminate\Support\Collection;

/**
 * Domain triggers that turn listing/roommate/chat events into notifications.
 */
class AlertNotifier
{
    public function __construct(private NotificationService $notifications) {}

    /** A listing was published or edited: tell users whose saved search now matches it. */
    public function watchlistAndSearchMatches(Post $post): void
    {
        $post->loadMissing('postDetail');

        $area = $post->area ?: $post->city;

        $entries = Watchlist::where('is_active', true)
            ->where('user_id', '!=', $post->owner_id)
            ->get()
            ->filter(fn (Watchlist $watchlist) => $watchlist->matches($post))
            ->unique('user_id')
            ->map(fn (Watchlist $watchlist) => [
                'userId' => $watchlist->user_id,
                'type' => NotificationType::SearchMatch,
                'title' => "New boarding available in {$area}",
                'message' => "A new listing matches your alert in {$area} within your tracked preferences.",
                'metadata' => [
                    'postId' => $post->id,
                    'watchlistId' => $watchlist->id,
                    'city' => $post->city,
                    'area' => $post->area,
                ],
            ]);

        $this->notifications->createMany($entries);
    }

    /** Three or more people watching the same neighbourhood means rooms there will go fast. */
    public function demandSpike(Post $post): void
    {
        $watchlists = Watchlist::where('is_active', true)
            ->where('city', $post->city)
            ->when($post->area, fn ($query, $area) => $query->where('area', $area))
            ->where('user_id', '!=', $post->owner_id)
            ->get();

        if ($watchlists->count() < 3) {
            return;
        }

        $area = $post->area ?: $post->city;

        $this->notifications->createMany(
            $watchlists->pluck('user_id')->unique()->map(fn (string $userId) => [
                'userId' => $userId,
                'type' => NotificationType::DemandSpike,
                'title' => "Demand is rising in {$area}",
                'message' => "Interest is increasing in {$area}. Rooms may fill fast, so it is worth checking new listings now.",
                'metadata' => [
                    'city' => $post->city,
                    'area' => $post->area,
                    'postId' => $post->id,
                    'demandSignals' => $watchlists->count(),
                ],
            ]),
        );
    }

    public function priceDrop(Post $post, ?int $previousRent): void
    {
        if ($previousRent === null || $post->rent >= $previousRent) {
            return;
        }

        $post->loadMissing('postDetail');

        $userIds = SavedPost::where('post_id', $post->id)->pluck('user_id')
            ->merge(
                Watchlist::where('is_active', true)
                    ->where('user_id', '!=', $post->owner_id)
                    ->get()
                    ->filter(fn (Watchlist $watchlist) => $watchlist->matches($post))
                    ->pluck('user_id'),
            )
            ->unique();

        $this->notifications->createMany($userIds->map(fn (string $userId) => [
            'userId' => $userId,
            'type' => NotificationType::PriceDrop,
            'title' => 'Price dropped on a tracked listing',
            'message' => "{$post->title} dropped from LKR {$previousRent} to LKR {$post->rent}.",
            'metadata' => [
                'postId' => $post->id,
                'previousRent' => $previousRent,
                'currentRent' => $post->rent,
            ],
        ]));
    }

    /** Tell people who saved a listing when it is nearly (or completely) out of slots. */
    public function lowAvailability(Post $post, int $remainingSlots): void
    {
        if ($remainingSlots > 1) {
            return;
        }

        $userIds = SavedPost::where('post_id', $post->id)
            ->where('user_id', '!=', $post->owner_id)
            ->pluck('user_id')
            ->unique();

        $this->notifications->createMany($userIds->map(fn (string $userId) => [
            'userId' => $userId,
            'type' => NotificationType::LowAvailability,
            'title' => $remainingSlots === 1 ? 'Only 1 room left' : 'Tracked listing is now full',
            'message' => $remainingSlots === 1
                ? "{$post->title} only has one remaining slot."
                : "{$post->title} is now out of available slots.",
            'metadata' => ['postId' => $post->id, 'remainingSlots' => $remainingSlots],
        ]));
    }

    /**
     * @param  Collection<int, array{user: array<string, mixed>, score: int, reasons: list<string>}>  $matches
     */
    public function roommateMatches(string $userId, Collection $matches): void
    {
        $strong = $matches->filter(fn (array $match) => $match['score'] >= 70)->take(3)->values();

        if ($strong->isEmpty()) {
            return;
        }

        $plural = $strong->count() > 1 ? 'es' : '';

        $entries = [[
            'userId' => $userId,
            'type' => NotificationType::RoommateMatchFound,
            'title' => 'High compatibility roommate matches found',
            'message' => "You now have {$strong->count()} strong roommate match{$plural} ready to review.",
            'metadata' => [
                'matches' => $strong->map(fn (array $match) => [
                    'userId' => $match['user']['id'],
                    'score' => $match['score'],
                ])->all(),
            ],
        ]];

        foreach ($strong as $match) {
            $entries[] = [
                'userId' => $match['user']['id'],
                'type' => NotificationType::RoommateMatchFound,
                'title' => 'A compatible roommate just appeared',
                'message' => 'A new high compatibility roommate match is now available in your finder.',
                'metadata' => ['matchedUserId' => $userId, 'score' => $match['score']],
            ];
        }

        $this->notifications->createMany($entries);
    }

    public function suspiciousChatActivity(
        string $receiverId,
        string $senderId,
        string $chatId,
        string $messageId,
        ScamFlag $flag,
        string $text,
    ): void {
        if ($flag === ScamFlag::Safe) {
            return;
        }

        $danger = $flag === ScamFlag::Danger;

        $this->notifications->create(
            userId: $receiverId,
            type: NotificationType::SuspiciousChatActivity,
            title: $danger ? 'Suspicious activity detected in chat' : 'Potentially risky message detected',
            message: $danger
                ? 'A message in your conversation was flagged as high risk. Avoid sending money before verifying the listing and owner.'
                : 'A message in your conversation may be risky. Take a moment to double-check the details.',
            metadata: [
                'senderId' => $senderId,
                'chatId' => $chatId,
                'messageId' => $messageId,
                'scamFlag' => $flag->value,
                'preview' => mb_substr($text, 0, 120),
            ],
        );
    }
}
