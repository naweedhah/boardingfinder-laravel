<?php

namespace App\Enums;

enum InquiryType: string
{
    use HasValues;

    case View = 'view';
    case Book = 'book';
}
