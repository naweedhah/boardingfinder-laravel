<?php

namespace App\Enums;

enum PreferredTenantGender: string
{
    use HasValues;

    case Male = 'male';
    case Female = 'female';
    case Any = 'any';
}
