<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Fired after PriorityEngine::refreshDepartmentScores() recalculates every
 * WAITING ticket's score for one department — batched into a single event
 * per department per refresh (refreshDepartmentScores runs on every
 * callNext() call), not one event per ticket, so a busy department doesn't
 * flood the channel.
 *
 * Private department channel ONLY — this is the one place priority_score
 * is allowed to appear, precisely because it never fans out to a public
 * channel the way TicketCalled/TicketStatusChanged do.
 */
class PriorityRecalculated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public int $departmentId;

    /** @var array<int, array{ticket_id: int, queue_number: string, priority_score: int}> */
    public array $tickets;

    public function __construct(int $departmentId, Collection $tickets)
    {
        $this->departmentId = $departmentId;
        $this->tickets = $tickets->map(fn ($ticket) => [
            'ticket_id' => $ticket->id,
            'queue_number' => $ticket->queue_number,
            'priority_score' => $ticket->priority_score,
        ])->values()->all();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("department.{$this->departmentId}")];
    }

    public function broadcastAs(): string
    {
        return 'PriorityRecalculated';
    }

    public function broadcastWith(): array
    {
        return [
            'department_id' => $this->departmentId,
            'tickets' => $this->tickets,
        ];
    }
}
