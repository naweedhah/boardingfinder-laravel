<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\Concerns\SerializesCamelCase;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'email', 'username', 'password', 'avatar', 'full_name', 'phone', 'gender',
    'role', 'is_active', 'is_verified',
])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'gender' => Gender::class,
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    public function isBoardingOwner(): bool
    {
        return $this->role === UserRole::BoardingOwner;
    }

    /** Display name used in notifications and emails. */
    public function displayName(): string
    {
        return $this->full_name ?: $this->username;
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(DevicePushToken::class);
    }

    public function ownedPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'owner_id');
    }

    public function savedPosts(): HasMany
    {
        return $this->hasMany(SavedPost::class);
    }

    /** Booking requests this user sent as a student. */
    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class, 'student_id');
    }

    /** Booking requests this user received as an owner. */
    public function receivedBookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class, 'owner_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function roommatePreference(): HasOne
    {
        return $this->hasOne(RoommatePreference::class);
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class)->withPivot('seen');
    }

    public function inquiriesSent(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'student_id');
    }

    public function inquiriesReceived(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'owner_id');
    }
}
