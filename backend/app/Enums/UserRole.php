<?php

namespace App\Enums;

enum UserRole: string
{
    use HasValues;

    case Student = 'student';
    case BoardingOwner = 'boardingOwner';
    case Admin = 'admin';
}
