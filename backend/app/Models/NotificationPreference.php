<?php

namespace App\Models;

use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'email_enabled', 'in_app_enabled', 'push_enabled', 'sms_enabled',
    'booking_updates', 'watchlist_alerts', 'search_alerts', 'price_alerts', 'demand_alerts',
    'inquiry_alerts', 'roommate_alerts', 'safety_alerts', 'moderation_alerts',
    'account_security_alerts',
])]
class NotificationPreference extends Model
{
    use HasUlids, SerializesCamelCase;

    /**
     * Every toggle the user is allowed to change (camelCase, as sent by the client), mapped to
     * its column.
     *
     * @var array<string, string>
     */
    public const TOGGLES = [
        'emailEnabled' => 'email_enabled',
        'inAppEnabled' => 'in_app_enabled',
        'pushEnabled' => 'push_enabled',
        'smsEnabled' => 'sms_enabled',
        'bookingUpdates' => 'booking_updates',
        'watchlistAlerts' => 'watchlist_alerts',
        'searchAlerts' => 'search_alerts',
        'priceAlerts' => 'price_alerts',
        'demandAlerts' => 'demand_alerts',
        'inquiryAlerts' => 'inquiry_alerts',
        'roommateAlerts' => 'roommate_alerts',
        'safetyAlerts' => 'safety_alerts',
        'moderationAlerts' => 'moderation_alerts',
        'accountSecurityAlerts' => 'account_security_alerts',
    ];

    protected $attributes = [
        'email_enabled' => true,
        'in_app_enabled' => true,
        'push_enabled' => true,
        'sms_enabled' => false,
        'booking_updates' => true,
        'watchlist_alerts' => true,
        'search_alerts' => true,
        'price_alerts' => true,
        'demand_alerts' => true,
        'inquiry_alerts' => true,
        'roommate_alerts' => true,
        'safety_alerts' => true,
        'moderation_alerts' => true,
        'account_security_alerts' => true,
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::TOGGLES, 'boolean');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
