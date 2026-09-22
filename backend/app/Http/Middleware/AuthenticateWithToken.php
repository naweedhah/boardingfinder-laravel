<?php

namespace App\Http\Middleware;

use App\Support\TokenCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a valid `token` cookie belonging to an active account.
 * Status codes and messages mirror the Node `verifyToken` middleware the client was built against.
 */
class AuthenticateWithToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookie(TokenCookie::NAME)) {
            return response()->json(['message' => 'Not Authenticated!'], 401);
        }

        $user = Auth::guard('token')->user();

        if (! $user) {
            return response()->json(['message' => 'Token is not Valid!'], 403);
        }

        if (! $user->is_active) {
            return response()
                ->json(['message' => 'Account access has been suspended.'], 403)
                ->withCookie(TokenCookie::forget());
        }

        // Make `$request->user()` / `Auth::user()` resolve to this user for the rest of the request.
        Auth::shouldUse('token');

        return $next($request);
    }
}
