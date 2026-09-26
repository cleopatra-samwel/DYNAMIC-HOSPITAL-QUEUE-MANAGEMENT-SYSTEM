<?php

namespace Tests\Feature;

use App\Broadcasting\ResilientBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;

/**
 * Calling a patient must keep working — and stay fast — when the
 * WebSocket server (Reverb) is not running.
 */
class BroadcastResilienceTest extends NotificationTestCase
{
    public function test_calling_a_patient_succeeds_when_the_websocket_server_is_down(): void
    {
        // Point real broadcasting at a port nothing listens on.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
            'broadcasting.connections.reverb.client_options' => ['connect_timeout' => 1, 'timeout' => 2],
        ]);
        app(BroadcastManager::class)->purge('reverb');

        $this->assertInstanceOf(ResilientBroadcaster::class, app(BroadcastManager::class)->connection('reverb'));

        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);

        $started = microtime(true);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertOk();
        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertOk();

        $this->assertSame('CALLED', $ticket->fresh()->status);
        // A dead server is waited on at most once per request, not once per event.
        $this->assertLessThan(15, microtime(true) - $started);
    }
}
