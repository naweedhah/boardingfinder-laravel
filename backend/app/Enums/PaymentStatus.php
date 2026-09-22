<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
