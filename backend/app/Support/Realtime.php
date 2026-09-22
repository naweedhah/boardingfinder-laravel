<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fire-and-forget wrapper around broadcasting. Realtime delivery is a nicety on top of the
 * REST API, so an unreachable WebSocket server must never turn a successful write into a 500.
 */
class Realtime
{
    public static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage());
        }
    }
}
