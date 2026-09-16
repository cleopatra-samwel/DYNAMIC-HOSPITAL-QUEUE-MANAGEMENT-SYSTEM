<?php

namespace App\Console\Commands;

use App\Models\QueueTicket;
use App\Models\User;
use App\Services\TicketTransitionService;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Attempts a single 'start-service' transition and prints "OK" or
 * "REJECTED:<status>" (plus the message). Exists so
 * CriticalRuleConcurrencyTest can spawn two real, separate OS processes —
 * each with its own DB connection — to genuinely exercise the Critical
 * Rule's lockForUpdate() under concurrent execution, the same way
 * queue:call-next-once already does for Phase 4's PriorityEngine.
 */
class StartServiceOnce extends Command
{
    protected $signature = 'queue:start-service-once {ticket} {user}';

    protected $description = 'Attempts to move one queue ticket to IN_SERVICE and prints the outcome.';

    public function handle(TicketTransitionService $transitions): int
    {
        $ticket = QueueTicket::findOrFail($this->argument('ticket'));
        $user = User::findOrFail($this->argument('user'));

        try {
            $transitions->apply($ticket, 'start-service', $user);
            $this->output->write('OK');
        } catch (HttpResponseException $e) {
            $this->output->write('REJECTED:'.$e->getResponse()->getStatusCode());
        } catch (\Throwable $e) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            $this->output->write("REJECTED:{$status}");
        }

        return self::SUCCESS;
    }
}
