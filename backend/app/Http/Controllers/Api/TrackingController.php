<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\QueueTicket;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/**
 * Phase 7 — the public patient tracking page. No auth (a patient has no
 * account), reached by the opaque tracking_token from the URL instead of
 * the visit's numeric id. Deliberately returns nothing patient-identifying
 * — no name, no contact — the same privacy rule the visit.{trackingToken}
 * broadcast channel follows (see App\Events\TicketCalled). This includes
 * `queue_numbers_ahead` (added alongside the doctor-selection/richer-
 * tracking work): ticket NUMBERS only, via a plucked column, never a
 * broader row that could carry another visit's patient/contact data.
 *
 * Phase 8 adds `notifications` and `estimated_wait_minutes` — extending
 * this existing response rather than adding a second endpoint, since the
 * frontend already refetches this same one on every relevant WebSocket
 * event (see TrackingPage.jsx).
 */
class TrackingController extends Controller
{
    /**
     * Fallback only — used when a department has no COMPLETED tickets yet
     * to measure from (a brand-new department, or one that's never
     * finished a service). No "average service time" model existed
     * anywhere in the codebase before this (confirmed by search) — Phase
     * 9's ReportController computes average WAIT time (created_at ->
     * called_at), a different measurement — so this stays a rough
     * heuristic, not a promise, exactly as it was before.
     */
    private const DEFAULT_MINUTES_PER_PATIENT = 5;

    /** How many recent completions to average — a rolling estimate that reacts to how the department is actually running today, not a since-day-one average. */
    private const SERVICE_TIME_SAMPLE_SIZE = 50;

    public function show(string $trackingToken)
    {
        $visit = Visit::where('tracking_token', $trackingToken)->firstOrFail();

        $service = $visit->services()->with(['department', 'queueTicket'])->latest('id')->first();
        $ticket = $service?->queueTicket;
        $queueNumbersAhead = $this->queueNumbersAhead($ticket, $service?->department_id);
        $queueNumbersBehind = $this->queueNumbersBehind($ticket, $service?->department_id);
        $position = $queueNumbersAhead === null ? null : count($queueNumbersAhead);

        return response()->json([
            'tracking_token' => $visit->tracking_token,
            'overall_status' => $visit->overall_status,
            'department' => $service?->department?->dept_name,
            'queue_number' => $ticket?->queue_number,
            'status' => $ticket?->status,
            'position' => $position,
            'queue_numbers_ahead' => $queueNumbersAhead,
            'queue_numbers_behind' => $queueNumbersBehind,
            'estimated_wait_minutes' => $position === null
                ? null
                : (int) round($position * $this->averageServiceMinutes($service->department_id)),
            // This visit's own notifications only — Notification rows are
            // scoped by visit_id at creation time (see NotificationService),
            // so there is no cross-visit data to accidentally leak here.
            'notifications' => Notification::where('visit_id', $visit->id)
                ->latest()
                ->limit(20)
                ->get(['type', 'message', 'created_at']),
        ]);
    }

    /**
     * The OTHER waiting tickets' queue_number values ahead of this one in
     * the same department, in the exact order PriorityEngine::callNext()
     * would call them — ticket numbers ONLY (a single plucked column, not
     * a row), never any other field from those visits/patients. Null when
     * this ticket isn't currently WAITING (nothing meaningful to queue
     * behind).
     *
     * @return list<string>|null
     */
    private function queueNumbersAhead(?QueueTicket $ticket, ?int $departmentId): ?array
    {
        if (! $ticket || $ticket->status !== 'WAITING' || ! $departmentId) {
            return null;
        }

        return QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', 'WAITING')
            ->where('id', '!=', $ticket->id)
            ->where(function ($q) use ($ticket) {
                $q->where('priority_score', '>', $ticket->priority_score)
                    ->orWhere(function ($q2) use ($ticket) {
                        $q2->where('priority_score', $ticket->priority_score)
                            ->where('created_at', '<', $ticket->created_at);
                    });
            })
            ->orderByDesc('priority_score')
            ->orderBy('created_at')
            ->pluck('queue_number')
            ->all();
    }

    /**
     * Mirror of queueNumbersAhead(): the waiting tickets that will be called
     * AFTER this one, in call order — ticket numbers only, same privacy rule.
     *
     * @return list<string>|null
     */
    private function queueNumbersBehind(?QueueTicket $ticket, ?int $departmentId): ?array
    {
        if (! $ticket || $ticket->status !== 'WAITING' || ! $departmentId) {
            return null;
        }

        return QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', 'WAITING')
            ->where('id', '!=', $ticket->id)
            ->where(function ($q) use ($ticket) {
                $q->where('priority_score', '<', $ticket->priority_score)
                    ->orWhere(function ($q2) use ($ticket) {
                        $q2->where('priority_score', $ticket->priority_score)
                            ->where('created_at', '>=', $ticket->created_at);
                    });
            })
            ->orderByDesc('priority_score')
            ->orderBy('created_at')
            ->pluck('queue_number')
            ->all();
    }

    /**
     * Average minutes from service_started_at to completed_at over this
     * department's most recent completions — real historical data, not a
     * guess, whenever any exists. Falls back to the fixed per-patient
     * default only when there's genuinely nothing to measure from yet.
     */
    private function averageServiceMinutes(?int $departmentId): float
    {
        if (! $departmentId) {
            return self::DEFAULT_MINUTES_PER_PATIENT;
        }

        $samples = QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', 'COMPLETED')
            ->whereNotNull('service_started_at')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->limit(self::SERVICE_TIME_SAMPLE_SIZE)
            ->get(['service_started_at', 'completed_at']);

        if ($samples->isEmpty()) {
            return self::DEFAULT_MINUTES_PER_PATIENT;
        }

        // absolute: true matches ReportController::waitTimeStats()'s exact
        // same pattern — Carbon's diffInMinutes() is signed by direction,
        // not absolute, by default in this app's Carbon version (confirmed
        // live: omitting this produced negative averages).
        $totalMinutes = $samples->sum(
            fn (QueueTicket $t) => Carbon::parse($t->completed_at)->diffInMinutes(Carbon::parse($t->service_started_at), absolute: true)
        );

        return $totalMinutes / $samples->count();
    }
}
