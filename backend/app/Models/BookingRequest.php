<?php

namespace App\Models;

use App\Enums\BookingRequestStatus;
use App\Models\Concerns\SerializesCamelCase;
use Database\Factories\BookingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'student_id', 'owner_id', 'post_id', 'status', 'applicant_name', 'applicant_phone',
    'applicant_address', 'applicant_nic', 'message', 'requested_move_in', 'approved_at',
    'rejected_at', 'confirmed_at', 'expires_at',
])]
class BookingRequest extends Model
{
    /** @use HasFactory<BookingRequestFactory> */
    use HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'status' => BookingRequestStatus::class,
            'requested_move_in' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(BookingPayment::class);
    }
}
