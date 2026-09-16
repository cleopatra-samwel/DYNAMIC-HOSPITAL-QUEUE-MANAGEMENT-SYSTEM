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
 * Fired after PriorityEngine::callNext() moves a ticket to CALLED — the
 * "next patient, please" moment that both the waiting-area display and
 * that patient's own tracking page need to reflect immediately.
 *
 * The payload is identical on every channel it reaches, including the
 * private staff one, and deliberately carries nothing beyond queue/status
 * data: since this same broadcast() call fans out to the public
 * waiting-display and visit channels, there's no per-channel way to
 * attach staff-only fields (priority_score) without leaking them
 * publicly too — see PriorityRecalculated for where that lives instead.
 */
class TicketCalled implements ShouldBroadcastNow, ShouldDispatchAfterCommit
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
        return 'TicketCalled';
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
