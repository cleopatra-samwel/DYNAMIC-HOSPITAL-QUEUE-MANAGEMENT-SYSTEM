<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Log;

/**
 * The Reverb/Pusher broadcaster, but a WebSocket server that is down never
 * breaks the queue: calling a patient, changing a ticket's status, etc. are
 * database actions that must succeed even when live screens cannot be
 * notified. The failure is logged, and — since every ticket action fires
 * several events — the rest of that request skips broadcasting instead of
 * waiting on a dead server again for each one. Screens catch up on their
 * own once Reverb is back (they refetch on reconnect).
 */
class ResilientBroadcaster extends PusherBroadcaster
{
    /** True once a broadcast failed during the current request. */
    private static bool $unreachable = false;

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (self::$unreachable) {
            return;
        }

        try {
            parent::broadcast($channels, $event, $payload);
        } catch (BroadcastException $exception) {
            self::$unreachable = true;

            Log::warning('Realtime broadcast skipped — the WebSocket server (Reverb) is not reachable. Start it with: php artisan reverb:start', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
