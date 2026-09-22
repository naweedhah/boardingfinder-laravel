<?php

namespace App\Enums;

enum BoardingStatus: string
{
    use HasValues;

    case Available = 'available';
    case Full = 'full';
    case Reserved = 'reserved';
    case Inactive = 'inactive';
}
