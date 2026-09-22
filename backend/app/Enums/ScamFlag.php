<?php

namespace App\Enums;

enum ScamFlag: string
{
    use HasValues;

    case Safe = 'safe';
    case Warning = 'warning';
    case Danger = 'danger';
}
