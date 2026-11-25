<?php

namespace App\Providers;

use App\Models\User;
use App\Support\TokenCookie;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Relations serialize under their camelCase method names (postDetail, bookingRequests…).
        Model::$snakeAttributes = false;
        // The client expects bare arrays/objects, not Laravel's `{ data: … }` envelope.
        JsonResource::withoutWrapping();
        // Fail loudly on N+1 lazy loading and silently-dropped attributes while running the test suite.
        Model::shouldBeStrict($this->app->environment('testing'));

        $this->registerTokenGuard();
        $this->registerRateLimiters();
    }

    /**
     * One guard for both clients, backed by Sanctum personal access tokens (hashed storage,
     * expiry, server-side revocation):
     *  - the React web client sends the token in an httpOnly `token` cookie, so page scripts
     *    can never read it;
     *  - the mobile app has no cookie jar it controls, so it keeps the token in the device's
     *    secure storage and sends `Authorization: Bearer <token>`.
     */
    private function registerTokenGuard(): void
    {
        Auth::viaRequest('cookie-token', function (Request $request): ?User {
            $plainTextToken = TokenCookie::fromRequest($request);

            if (! is_string($plainTextToken) || $plainTextToken === '') {
                return null;
            }

            $token = PersonalAccessToken::findToken($plainTextToken);

            if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
                return null;
            }

            $user = $token->tokenable;

            return $user instanceof User ? $user->withAccessToken($token) : null;
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)
            ->by($request->user('token')?->getKey() ?: $request->ip()));

        // Slow down credential stuffing: per username + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(strtolower((string) $request->input('username')).'|'.$request->ip()));
    }
}
