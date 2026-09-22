<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `->middleware('role:admin')` or `role:student,boardingOwner`.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role?->value, $roles, true)) {
            $message = $roles === ['admin'] ? 'Admin access required' : 'Not authorized';

            return response()->json(['message' => $message], 403);
        }

        return $next($request);
    }
}
