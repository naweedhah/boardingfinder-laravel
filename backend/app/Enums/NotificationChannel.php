<?php

namespace App\Enums;

enum NotificationChannel: string
{
    use HasValues;

    case Email = 'email';
    case InApp = 'inApp';
    case Sms = 'sms';
    case Push = 'push';
}
