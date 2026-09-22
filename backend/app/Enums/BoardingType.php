<?php

namespace App\Enums;

enum BoardingType: string
{
    use HasValues;

    case SingleRoom = 'singleRoom';
    case SharedRoom = 'sharedRoom';
    case Annex = 'annex';
    case Hostel = 'hostel';
    case HouseShare = 'houseShare';
}
