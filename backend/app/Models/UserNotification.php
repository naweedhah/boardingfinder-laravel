<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification. Named `UserNotification` (table `user_notifications`) to stay clear
 * of Laravel's own `notifications` table and `Illuminate\Notifications\Notification`.
 */
#[Fillable([
    'user_id', 'type', 'channel', 'title', 'message', 'is_read', 'sent_at', 'read_at', 'metadata',
])]
class UserNotification extends Model
{
    use HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'is_read' => 'boolean',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
