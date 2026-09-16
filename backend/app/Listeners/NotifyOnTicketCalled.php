<?php

namespace App\Listeners;

use App\Events\TicketCalled;
use App\Models\QueueTicket;
use App\Services\NotificationService;

/**
 * Phase 8 — reacts to Phase 7's existing TicketCalled broadcast (fired
 * only by PriorityEngine::callNext's "Call Next Patient" path) to write a
 * patient-facing CALLED notification. The OTHER way a ticket becomes
 * CALLED — the manual "Call" button on a specific ticket — goes through
 * TicketTransitionService::apply() instead, which fires TicketStatusChanged
 * (never both for the same call), so NotifyOnTicketStatusChanged covers
 * that path without double-notifying here.
 */
class NotifyOnTicketCalled
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketCalled $event): void
    {
        $ticket = QueueTicket::find($event->ticketId);

        if ($ticket) {
            $this->notifications->notifyCalled($ticket);
        }
    }
}
