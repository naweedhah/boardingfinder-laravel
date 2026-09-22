<?php

namespace App\Models;

use App\Enums\BoardingType;
use App\Enums\PreferredTenantGender;
use App\Models\Concerns\SerializesCamelCase;
use Database\Factories\WatchlistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Either a watch on one specific listing (`post_id` set, used by the waitlist) or a saved
 * search alert (`post_id` null) that is matched against newly published/changed listings.
 */
#[Fillable([
    'user_id', 'post_id', 'city', 'area', 'min_budget', 'max_budget', 'preferred_tenant_gender',
    'boarding_type', 'min_capacity', 'wifi_required', 'meals_required',
    'attached_bathroom_needed', 'is_active',
])]
class Watchlist extends Model
{
    /** @use HasFactory<WatchlistFactory> */
    use HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'min_budget' => 'integer',
            'max_budget' => 'integer',
            'min_capacity' => 'integer',
            'preferred_tenant_gender' => PreferredTenantGender::class,
            'boarding_type' => BoardingType::class,
            'wifi_required' => 'boolean',
            'meals_required' => 'boolean',
            'attached_bathroom_needed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** Does the given (detail-loaded) listing satisfy this saved search? */
    public function matches(Post $post): bool
    {
        if (! $this->is_active || $this->post_id) {
            return false;
        }

        $detail = $post->postDetail;

        return ! ($this->city && $this->city !== $post->city)
            && ! ($this->area && $this->area !== $post->area)
            && ! ($this->boarding_type && $this->boarding_type !== $post->boarding_type)
            && ! (
                $this->preferred_tenant_gender
                && $this->preferred_tenant_gender !== PreferredTenantGender::Any
                && $this->preferred_tenant_gender !== $post->preferred_tenant_gender
            )
            && ! ($this->min_budget !== null && $post->rent < $this->min_budget)
            && ! ($this->max_budget !== null && $post->rent > $this->max_budget)
            && ! ($this->min_capacity !== null && $post->capacity < $this->min_capacity)
            && ! ($this->wifi_required && ! $detail?->wifi)
            && ! ($this->meals_required && ! $detail?->meals_provided)
            && ! ($this->attached_bathroom_needed && ! $detail?->attached_bathroom);
    }
}
