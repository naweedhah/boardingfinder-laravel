<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The httpOnly cookie that carries the API token for the web client. Set by login, cleared by logout.
 * The mobile app sends the same kind of token as a bearer token instead.
 */
class TokenCookie
{
    public const NAME = 'token';

    public const LIFETIME_DAYS = 7;

    /** The token a request carries: a bearer header (mobile) wins over the cookie (web). */
    public static function fromRequest(Request $request): ?string
    {
        $token = $request->bearerToken() ?: $request->cookie(self::NAME);

        return is_string($token) && $token !== '' ? $token : null;
    }

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
