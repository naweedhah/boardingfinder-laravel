<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The httpOnly cookie that carries the API token. Set by login, cleared by logout.
 */
class TokenCookie
{
    public const NAME = 'token';

    public const LIFETIME_DAYS = 7;

    public static function make(string $plainTextToken): Cookie
    {
        return new Cookie(
            name: self::NAME,
            value: $plainTextToken,
            expire: now()->addDays(self::LIFETIME_DAYS),
            path: '/',
            secure: app()->isProduction(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public static function forget(): Cookie
    {
        return new Cookie(
            name: self::NAME,
            value: '',
            expire: 1,
            path: '/',
            secure: app()->isProduction(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
