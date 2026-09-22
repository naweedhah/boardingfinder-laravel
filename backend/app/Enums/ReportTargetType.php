<?php

namespace App\Enums;

enum ReportTargetType: string
{
    use HasValues;

    case User = 'user';
    case Boarding = 'boarding';
    case BookingRequest = 'bookingRequest';
    case Message = 'message';
}
