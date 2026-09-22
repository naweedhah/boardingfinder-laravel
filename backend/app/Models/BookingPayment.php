<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_request_id', 'amount', 'status', 'method', 'bank_reference', 'proof_image',
    'submitted_at', 'verified_at', 'notes',
])]
class BookingPayment extends Model
{
    use HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }
}
