<?php

namespace App\Models;

use App\Models\Concerns\SerializesCamelCase;
use Database\Factories\PostDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'post_id', 'description', 'wifi', 'meals_provided', 'kitchen_access', 'parking',
    'attached_bathroom', 'furnished', 'pet_allowed', 'nearest_campus', 'distance_to_campus',
    'distance_to_bus_stop', 'distance_to_town', 'rules',
])]
class PostDetail extends Model
{
    /** @use HasFactory<PostDetailFactory> */
    use HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'wifi' => 'boolean',
            'meals_provided' => 'boolean',
            'kitchen_access' => 'boolean',
            'parking' => 'boolean',
            'attached_bathroom' => 'boolean',
            'furnished' => 'boolean',
            'pet_allowed' => 'boolean',
            'distance_to_campus' => 'integer',
            'distance_to_bus_stop' => 'integer',
            'distance_to_town' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
