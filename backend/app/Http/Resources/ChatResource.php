<?php

namespace App\Http\Resources;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chat as the client expects it: participant ids in `userIDs`, the ids that have read the
 * latest message in `seenBy`, and (when viewed by someone) the other participant as `receiver`.
 * Expects `users` to be loaded.
 *
 * @property Chat $resource
 */
class ChatResource extends JsonResource
{
    private ?string $viewerId = null;

    public function forViewer(string $userId): static
    {
        $this->viewerId = $userId;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $users = $this->resource->users;

        return [
            'id' => $this->resource->id,
            'userIDs' => $users->pluck('id')->values(),
            'seenBy' => $users->filter(fn (User $user) => (bool) $user->pivot->seen)->pluck('id')->values(),
            'lastMessage' => $this->resource->last_message,
            'createdAt' => $this->resource->created_at,
            'updatedAt' => $this->resource->updated_at,
            $this->mergeWhen($this->viewerId !== null, fn () => [
                'receiver' => $users->first(fn (User $user) => $user->id !== $this->viewerId)?->only(['id', 'username', 'avatar']),
            ]),
            'messages' => $this->whenLoaded('messages'),
        ];
    }
}
