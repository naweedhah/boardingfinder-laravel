<?php

use App\Http\Middleware\AuthenticateWithToken;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth goes through the same cookie-token middleware as the REST API
    // (POST /api/broadcasting/auth).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth.token']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token' => AuthenticateWithToken::class,
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        // The client reads `message` for a toast and `errors[0]` for the first field error.
        $exceptions->render(fn (ValidationException $e) => response()->json([
            'message' => collect($e->errors())->flatten()->first() ?? 'Validation failed',
            'errors' => collect($e->errors())->flatten()->values(),
        ], 422));

        $exceptions->render(fn (AuthenticationException $e) => response()->json(
            ['message' => 'Not Authenticated!'], 401,
        ));

        // Route model binding failures reach us as a NotFoundHttpException wrapping the original
        // ModelNotFoundException; keep the Node API's per-resource "not found" wording.
        $exceptions->render(function (NotFoundHttpException $e) {
            $previous = $e->getPrevious();

            if (! $previous instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Route not found'], 404);
            }

            return response()->json(['message' => match (class_basename($previous->getModel())) {
                'Post' => 'Listing not found',
                'BookingRequest' => 'Booking not found',
                'Inquiry' => 'Inquiry not found',
                'Report' => 'Report not found',
                'Chat' => 'Chat not found!',
                'UserNotification' => 'Notification not found!',
                'User' => 'User not found!',
                default => 'Not found',
            }], 404);
        });
    })->create();
