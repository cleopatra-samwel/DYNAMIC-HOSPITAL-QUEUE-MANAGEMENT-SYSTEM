<?php

namespace App\Listeners;

use App\Events\PriorityRecalculated;
use App\Models\Notification;
use App\Models\QueueTicket;
use App\Services\NotificationService;

/**
 * Phase 8 — PriorityRecalculated fires on every callNext() call (batched,
 * one event per department per refresh) with every WAITING ticket's
 * current priority_score. The top 3 by score are "approaching" — this
 * mirrors PriorityEngine::callNext's own selection order (priority_score
 * desc; the event payload doesn't carry created_at for an exact
 * tie-break, so ties fall back to array order, which is an acceptable
 * approximation for a non-critical "you're close" notification).
 *
 * Fires only once per ticket, ever — this event fires on EVERY callNext
 * call, so without a guard a ticket sitting in the top 3 across several
 * calls would get spammed. Existence-check against the notifications
 * table is the guard (same alert-fatigue principle as the Long-Waiting
 * Alert's suppression window, just simpler since this type never needs
 * to re-fire for the same ticket at all).
 */
class NotifyApproachingOnPriorityRecalculated
{
    private const APPROACHING_RANK_THRESHOLD = 3;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PriorityRecalculated $event): void
    {
        $topTicketIds = collect($event->tickets)
            ->sortByDesc('priority_score')
            ->take(self::APPROACHING_RANK_THRESHOLD)
            ->pluck('ticket_id');

        if ($topTicketIds->isEmpty()) {
            return;
        }

        $alreadyNotifiedTicketIds = Notification::query()
            ->where('type', 'APPROACHING')
            ->whereIn('queue_ticket_id', $topTicketIds)
            ->pluck('queue_ticket_id');

        $ticketIdsToNotify = $topTicketIds->diff($alreadyNotifiedTicketIds);

        if ($ticketIdsToNotify->isEmpty()) {
            return;
        }

        QueueTicket::query()
            ->whereIn('id', $ticketIdsToNotify)
            ->where('status', 'WAITING')
            ->get()
            ->each(fn (QueueTicket $ticket) => $this->notifications->notifyApproaching($ticket));
    }
}
