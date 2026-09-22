<?php

namespace App\Enums;

enum ComplaintTargetType: string
{
    use HasValues;

    case Platform = 'platform';
    case User = 'user';
    case Boarding = 'boarding';
    case Booking = 'booking';
}
