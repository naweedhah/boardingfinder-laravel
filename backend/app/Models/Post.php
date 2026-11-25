<?php

namespace App\Models;

use App\Casts\ImageUrlList;
use App\Enums\BoardingStatus;
use App\Enums\BoardingType;
use App\Enums\BookingRequestStatus;
use App\Enums\PreferredTenantGender;
use App\Models\Concerns\SerializesCamelCase;
use App\Services\DemandService;
use App\Support\Geo;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'owner_id', 'title', 'rent', 'images', 'address', 'city', 'area', 'latitude', 'longitude',
    'boarding_type', 'status', 'capacity', 'bathroom_count', 'preferred_tenant_gender',
])]
// Raw counts from withDemandCounts() are re-exposed under friendlier names by DemandService.
#[Hidden(['saved_posts_count', 'inquiries_count', 'booking_requests_count', 'active_booking_requests_count'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'rent' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'images' => ImageUrlList::class,
            'boarding_type' => BoardingType::class,
            'status' => BoardingStatus::class,
            'preferred_tenant_gender' => PreferredTenantGender::class,
            'capacity' => 'integer',
            'bathroom_count' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function postDetail(): HasOne
    {
        return $this->hasOne(PostDetail::class);
    }

    public function savedPosts(): HasMany
    {
        return $this->hasMany(SavedPost::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /**
     * Adds saved/inquiry/booking counts plus the number of slots currently held by live
     * booking requests, in a single query. Feeds {@see DemandService}.
     */
    /**
     * Rows inside the bounding box of a circle around a point. This is the cheap, index-friendly
     * pre-filter; callers compute the exact distance afterwards (see {@see Geo}).
     */
    #[Scope]
    protected function withinBoundingBox(Builder $query, float $lat, float $lng, float $radiusKm): void
    {
        $box = Geo::boundingBox($lat, $lng, $radiusKm);

        $query->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude', [$box['minLat'], $box['maxLat']])
            ->whereBetween('longitude', [$box['minLng'], $box['maxLng']]);
    }

    #[Scope]
    protected function withDemandCounts(Builder $query): void
    {
        $query->withCount([
            'savedPosts',
            'inquiries',
            'bookingRequests',
            'bookingRequests as active_booking_requests_count' => fn (Builder $q) => $q
                ->whereIn('status', BookingRequestStatus::activeValues()),
        ]);
    }
}
