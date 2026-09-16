<?php

namespace App\Services;

use App\Events\PriorityRecalculated;
use App\Events\TicketCalled;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Support\QueueJourney;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — replaces static priority_score with a dynamically recalculated
 * one, and provides the concurrency-safe "Call Next Patient" selection.
 *
 * Priority Score = priority_level.weight + (waiting_minutes * AGING_POINTS_PER_MINUTE)
 */
class PriorityEngine
{
    public function calculateScore(QueueTicket $ticket): int
    {
        $ticket->loadMissing('priorityLevel');

        $waitingMinutes = now()->diffInMinutes($ticket->created_at, absolute: true);
        $agingPoints = $waitingMinutes * (int) config('queue_priority.aging_points_per_minute');

        return $ticket->priorityLevel->weight + $agingPoints;
    }

    /**
     * Recalculates and saves priority_score for every WAITING ticket in a
     * department. Not itself lock-protected — recomputing the same score
     * twice under concurrent calls is harmless (idempotent), the actual
     * safety guarantee lives in callNext()'s row lock below.
     */
    public function refreshDepartmentScores(int $departmentId): void
    {
        $tickets = QueueTicket::query()
            ->with('priorityLevel')
            ->whereHas('service', fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', 'WAITING')
            ->get()
            ->each(function (QueueTicket $ticket) {
                $ticket->update(['priority_score' => $this->calculateScore($ticket)]);
            });

        if ($tickets->isNotEmpty()) {
            PriorityRecalculated::dispatch($departmentId, $tickets);
        }
    }

    /**
     * The full "Call Next Patient" sequence, safe under concurrent calls:
     * wrapped in a transaction, and the ticket selection uses
     * lockForUpdate() so two simultaneous callers can never both select
     * the same WAITING ticket — the second caller's locked SELECT
     * re-evaluates the WHERE clause once the first caller's UPDATE
     * commits, so it naturally falls through to the next-highest ticket
     * (or returns null) instead of racing for the same row.
     *
     * Doctor-scoping (optional patient-preferred doctor at registration —
     * see VisitRegistrationService): a ticket whose service.doctor_id is
     * set is only eligible for the matching doctor's own callNext() call;
     * `doctor_id IS NULL` (the overwhelming majority — no preference
     * expressed) remains callable by anyone, exactly as before this
     * feature existed. This needs no role check at all — it's just
     * "doctor_id is null, or belongs to whoever is calling" — so a
     * non-Consultation callNext() (doctor_id always null there) is
     * completely unaffected, and existing tickets/tests (doctor_id
     * defaults null) see no behavior change.
     *
     * Known limitation, not solved here: a ticket reserved for a specific
     * doctor who never shows up for their shift has no automatic
     * fallback — it just sits WAITING, ineligible to every OTHER doctor's
     * callNext(), until someone clears its doctor_id back to null.
     */
    public function callNext(int $departmentId, int $performedByUserId): ?QueueTicket
    {
        return DB::transaction(function () use ($departmentId, $performedByUserId) {
            $this->refreshDepartmentScores($departmentId);

            $ticket = QueueTicket::query()
                ->whereHas('service', function ($q) use ($departmentId, $performedByUserId) {
                    $q->where('department_id', $departmentId)
                        ->where(function ($q2) use ($performedByUserId) {
                            $q2->whereNull('doctor_id')->orWhere('doctor_id', $performedByUserId);
                        });
                })
                ->where('status', 'WAITING')
                ->orderByDesc('priority_score')
                ->orderBy('created_at') // tie-break: earliest created_at wins
                ->lockForUpdate()
                ->first();

            if (! $ticket) {
                return null;
            }

            $ticket->update([
                'status' => 'CALLED',
                'called_at' => now(),
                'call_attempts' => $ticket->call_attempts + 1,
            ]);

            QueueEvent::create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => 'CALLED',
                'performed_by' => $performedByUserId,
                'event_time' => now(),
            ]);

            $ticket->loadMissing(['service.department', 'service.visit']);
            $ticket->service->visit->update([
                'overall_status' => QueueJourney::stateFor($ticket->service->department->dept_code, 'CALLED'),
            ]);

            $freshTicket = $ticket->fresh(['priorityLevel', 'service.department', 'service.visit.patient']);
            TicketCalled::dispatch($freshTicket);

            return $freshTicket;
        });
    }
}
