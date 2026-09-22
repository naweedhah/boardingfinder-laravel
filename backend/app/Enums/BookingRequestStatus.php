<?php

namespace App\Enums;

enum BookingRequestStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PaymentPending = 'paymentPending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Statuses that still occupy one of the listing's slots.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Approved, self::PaymentPending, self::Confirmed];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::active());
    }
}
