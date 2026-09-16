<?php

namespace App\Events;

use App\Models\QueueTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after TicketTransitionService::apply() succeeds — covers every
 * status a ticket can move to through that path (CALLED via the manual
 * "call this ticket" action, IN_SERVICE, COMPLETED, NO_SHOW, CANCELLED,
 * ON_HOLD, TRANSFERRED) as one event with the new status as a field,
 * rather than one event class per transition.
 *
 * Same privacy reasoning as TicketCalled: one payload fans out to the
 * private department channel and the public waiting-display/visit
 * channels alike, so it never carries priority_score or anything
 * patient-identifying.
 */
class TicketStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public int $ticketId;

    public string $queueNumber;

    public string $status;

    public int $departmentId;

    private ?string $trackingToken;

    public function __construct(QueueTicket $ticket)
    {
        $ticket->loadMissing('service.department', 'service.visit');

        $this->ticketId = $ticket->id;
        $this->queueNumber = $ticket->queue_number;
        $this->status = $ticket->status;
        $this->departmentId = $ticket->service->department_id;
        $this->trackingToken = $ticket->service->visit->tracking_token;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("department.{$this->departmentId}"),
            new Channel("waiting-display.{$this->departmentId}"),
            new Channel("visit.{$this->trackingToken}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TicketStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'queue_number' => $this->queueNumber,
            'status' => $this->status,
            'department_id' => $this->departmentId,
        ];
    }
}
