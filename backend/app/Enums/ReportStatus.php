<?php

namespace App\Enums;

enum ReportStatus: string
{
    use HasValues;

    case Open = 'open';
    case UnderReview = 'underReview';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
