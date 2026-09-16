<?php

namespace App\Listeners;

use App\Events\TicketStatusChanged;
use App\Models\QueueTicket;
use App\Services\NotificationService;

/**
 * Phase 8 — TicketStatusChanged fires for every transition
 * TicketTransitionService::apply() makes (the manual "Call" action,
 * start-service, complete, no-show, cancel, hold, transfer). Only two of
 * those are patient-facing notification-worthy per the spec: the manual
 * call path reaching CALLED, and COMPLETED. Everything else (IN_SERVICE,
 * NO_SHOW, CANCELLED, ON_HOLD, TRANSFERRED-as-a-ticket-status) is
 * deliberately silent here — TRANSFERRED-the-notification-type means a
 * NEW service being opened for the visit (Phase 5's flow), which is a
 * different thing from this ticket's own status becoming the string
 * "TRANSFERRED" (an old ticket being closed out) and is handled directly
 * in ServiceFlowController instead, since no broadcast event covers
 * service creation.
 */
class NotifyOnTicketStatusChanged
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TicketStatusChanged $event): void
    {
        if (! in_array($event->status, ['CALLED', 'COMPLETED'], true)) {
            return;
        }

        $ticket = QueueTicket::find($event->ticketId);

        if (! $ticket) {
            return;
        }

        match ($event->status) {
            'CALLED' => $this->notifications->notifyCalled($ticket),
            'COMPLETED' => $this->notifications->notifyCompleted($ticket),
        };
    }
}
