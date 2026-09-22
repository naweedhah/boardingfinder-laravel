<?php

namespace App\Enums;

enum Gender: string
{
    use HasValues;

    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case PreferNotToSay = 'preferNotToSay';
}
