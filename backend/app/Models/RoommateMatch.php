<?php

namespace App\Models;

use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_a_id', 'user_b_id', 'score', 'reasons', 'is_active'])]
class RoommateMatch extends Model
{
    use HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reasons' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }
}
