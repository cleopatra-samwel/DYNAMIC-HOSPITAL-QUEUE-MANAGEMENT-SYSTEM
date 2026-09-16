<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QueueTicket;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 9 — Administrator-only reporting/analytics. Despite the spec
 * describing this as extending an existing controller, no ReportController
 * (or any reporting endpoint) existed anywhere in the codebase before this
 * — confirmed by search — so every method here is new.
 *
 * Status-value casing is genuinely inconsistent across tables in this
 * codebase (confirmed via a live query, not assumed): queue_tickets.status
 * is SCREAMING_SNAKE_CASE (WAITING, CALLED, COMPLETED, NO_SHOW, CANCELLED,
 * IN_SERVICE, ON_HOLD, TRANSFERRED), services.status is PascalCase
 * (Pending, Active, Completed, Cancelled, OnHold), and visits.overall_status
 * mixes both — the DB column's own default is the literal string
 * 'Registered' (mixed case, set in the migration) while every other value
 * QueueJourney actually writes is SCREAMING_SNAKE_CASE (WAITING_CONS,
 * IN_CONS, COMPLETED, EXITED, ON_HOLD, TRANSFERRED — 'REGISTERED' from
 * QueueJourney's own default arm is never actually reached in practice).
 * Every query below uses the queue_tickets/visits values exactly as
 * confirmed live, not the values that would be "expected" by symmetry.
 *
 * "Wait time" is defined as created_at -> called_at throughout (matches
 * queue_tickets.called_at, set by both the callNext and manual-call
 * paths — see PriorityEngine::callNext and TicketTransitionService).
 * service_started_at was considered instead (per the spec's suggestion)
 * but rejected: a live data check found rows where it's null even on a
 * COMPLETED ticket (data created directly rather than through every HTTP
 * transition in sequence), so it isn't reliably populated the way
 * called_at is guaranteed to be for any ticket that ever left WAITING.
 *
 * The AVG/MAX wait-time figures are computed in PHP over an already
 * date-and-status-filtered (bounded, single-day) result set, not a raw
 * SQL date-diff aggregate — Postgres (production) and SQLite (this app's
 * test suite) don't share a portable date-diff expression, and this
 * phase's test suite runs against SQLite. Every OTHER aggregate here
 * (COUNT, AVG on a plain numeric column like priority_score) uses real
 * database aggregation via the query builder, per the spec's actual
 * concern (no unbounded pull-everything-into-PHP scans as data grows) —
 * only the date-diff reduction itself happens in PHP, and only over rows
 * already filtered at the database level to one day.
 *
 * Each report's figures are built by one private *Data() method, called
 * by both the JSON action and pdf() — so the PDF can never drift from
 * what the screen shows, by construction rather than by discipline.
 */
class ReportController extends Controller
{
    private const REPORT_TYPES = ['daily-patients', 'waiting-time', 'department', 'queue-performance', 'emergency'];

    private function assertAdministrator(Request $request): void
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may view reports.');
    }

    private function resolveDate(Request $request): string
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return $data['date'] ?? now()->toDateString();
    }

    /** Average/max minutes from created_at to called_at over an already-filtered ticket collection (id/created_at/called_at only). Null when no ticket in the set has been called yet. */
    private function waitTimeStats($tickets): array
    {
        $minutes = $tickets->filter(fn ($t) => $t->called_at !== null)
            ->map(fn ($t) => Carbon::parse($t->called_at)->diffInMinutes(Carbon::parse($t->created_at), absolute: true));

        if ($minutes->isEmpty()) {
            return ['average_minutes' => null, 'max_minutes' => null, 'sample_size' => 0];
        }

        return [
            'average_minutes' => round($minutes->avg(), 1),
            'max_minutes' => $minutes->max(),
            'sample_size' => $minutes->count(),
        ];
    }

    private function dailyPatientsData(string $date): array
    {
        $visits = Visit::whereDate('visit_date', $date);

        return [
            'date' => $date,
            'total_visits' => (clone $visits)->count(),
            'normal_count' => (clone $visits)->where('patient_type', 'Normal')->count(),
            'emergency_count' => (clone $visits)->where('patient_type', 'Emergency')->count(),
            'completed_count' => (clone $visits)->where('overall_status', 'COMPLETED')->count(),
        ];
    }

    private function waitingTimeData(string $date): array
    {
        $tickets = QueueTicket::query()
            ->select('queue_tickets.id', 'queue_tickets.created_at', 'queue_tickets.called_at', 'services.department_id')
            ->join('services', 'services.id', '=', 'queue_tickets.service_id')
            ->whereDate('queue_tickets.created_at', $date)
            ->get();

        $byDepartment = $tickets->groupBy('department_id');
        $departments = Department::whereIn('id', $byDepartment->keys())->get()->keyBy('id');

        $perDepartment = $byDepartment->map(function ($deptTickets, $departmentId) use ($departments) {
            return array_merge(
                ['department_id' => (int) $departmentId, 'department_name' => $departments->get($departmentId)?->dept_name],
                $this->waitTimeStats($deptTickets)
            );
        })->values();

        return ['date' => $date, 'departments' => $perDepartment];
    }

    private function departmentData(string $date, int $departmentId): array
    {
        $ticketsForDept = fn () => QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('department_id', $departmentId))
            ->whereDate('created_at', $date);

        // "Waiting" here means: of the tickets CREATED on this date, how
        // many are still WAITING right now — a historical, date-scoped
        // count, distinct from system-summary's live "currently waiting
        // across all departments" figure (see systemSummaryData()).
        $servedCount = (clone $ticketsForDept())->where('status', 'COMPLETED')->count();
        $waitingCount = (clone $ticketsForDept())->where('status', 'WAITING')->count();
        $noShowCount = (clone $ticketsForDept())->where('status', 'NO_SHOW')->count();

        // priority_score only reflects "at time of call" once a ticket has
        // actually left WAITING (refreshDepartmentScores no longer touches
        // it after that point) — called_at not null is exactly that set.
        $averagePriorityScore = (clone $ticketsForDept())->whereNotNull('called_at')->avg('priority_score');

        return [
            'date' => $date,
            'department_id' => $departmentId,
            'department_name' => Department::find($departmentId)?->dept_name,
            'tickets_served' => $servedCount,
            'tickets_waiting' => $waitingCount,
            'average_priority_score_at_call' => $averagePriorityScore !== null ? round($averagePriorityScore, 1) : null,
            'no_show_count' => $noShowCount,
        ];
    }

    private function queuePerformanceData(string $date): array
    {
        $tickets = fn () => QueueTicket::whereDate('created_at', $date);

        return [
            'date' => $date,
            'total_served' => (clone $tickets())->where('status', 'COMPLETED')->count(),
            'total_waiting' => (clone $tickets())->where('status', 'WAITING')->count(),
            'total_no_shows' => (clone $tickets())->where('status', 'NO_SHOW')->count(),
            'total_cancelled' => (clone $tickets())->where('status', 'CANCELLED')->count(),
        ];
    }

    private function emergencyData(string $date): array
    {
        $emergencyVisits = Visit::whereDate('visit_date', $date)->where('patient_type', 'Emergency');

        $confirmedCount = (clone $emergencyVisits)->where('emergency_confirmed', true)->count();
        $pendingCount = (clone $emergencyVisits)->where('emergency_confirmed', false)->count();

        $emergencyTickets = QueueTicket::query()
            ->select('queue_tickets.id', 'queue_tickets.created_at', 'queue_tickets.called_at')
            ->join('services', 'services.id', '=', 'queue_tickets.service_id')
            ->join('visits', 'visits.id', '=', 'services.visit_id')
            ->whereDate('visits.visit_date', $date)
            ->where('visits.patient_type', 'Emergency')
            ->get();

        $waitStats = $this->waitTimeStats($emergencyTickets);

        return [
            'date' => $date,
            'emergency_count' => (clone $emergencyVisits)->count(),
            'confirmed_count' => $confirmedCount,
            'pending_confirmation_count' => $pendingCount,
            'average_minutes_to_called' => $waitStats['average_minutes'],
        ];
    }

    public function dailyPatients(Request $request)
    {
        $this->assertAdministrator($request);

        return response()->json($this->dailyPatientsData($this->resolveDate($request)));
    }

    public function waitingTime(Request $request)
    {
        $this->assertAdministrator($request);

        return response()->json($this->waitingTimeData($this->resolveDate($request)));
    }

    public function department(Request $request)
    {
        $this->assertAdministrator($request);
        $data = $request->validate(['department_id' => ['required', 'exists:departments,id']]);

        return response()->json($this->departmentData($this->resolveDate($request), (int) $data['department_id']));
    }

    public function queuePerformance(Request $request)
    {
        $this->assertAdministrator($request);

        return response()->json($this->queuePerformanceData($this->resolveDate($request)));
    }

    public function emergency(Request $request)
    {
        $this->assertAdministrator($request);

        return response()->json($this->emergencyData($this->resolveDate($request)));
    }

    /**
     * Powers the Administrator Dashboard's summary cards — a different UI
     * surface from the Reports & Analytics page above, deliberately not
     * date-scoped the same way (it's "right now", not "on this date").
     */
    public function systemSummary(Request $request)
    {
        $this->assertAdministrator($request);
        $today = now()->toDateString();

        $currentlyWaiting = QueueTicket::where('status', 'WAITING')->get(['id', 'created_at']);
        $longestWaiting = $currentlyWaiting->sortBy('created_at')->first();

        return response()->json([
            'total_patients_today' => Visit::whereDate('visit_date', $today)->count(),
            'currently_waiting' => $currentlyWaiting->count(),
            'total_served_today' => QueueTicket::whereDate('created_at', $today)->where('status', 'COMPLETED')->count(),
            'longest_currently_waiting' => $longestWaiting ? [
                'ticket_id' => $longestWaiting->id,
                'waiting_minutes' => now()->diffInMinutes(Carbon::parse($longestWaiting->created_at)),
            ] : null,
        ]);
    }

    /**
     * One shared PDF renderer for all 5 report types — a simple, clearly
     * labeled layout (report name, date generated, the same figures the
     * screen shows), streamed inline (not a forced download) so it opens
     * in the browser's own PDF viewer, consistent with the rest of this
     * app never yanking the user out of it.
     */
    public function pdf(Request $request, string $type)
    {
        $this->assertAdministrator($request);
        abort_unless(in_array($type, self::REPORT_TYPES, true), 404);

        $date = $this->resolveDate($request);

        [$title, $data] = match ($type) {
            'daily-patients' => ['Daily Patients Report', $this->dailyPatientsData($date)],
            'waiting-time' => ['Waiting-Time Report', $this->waitingTimeData($date)],
            'department' => (function () use ($request, $date) {
                $validated = $request->validate(['department_id' => ['required', 'exists:departments,id']]);

                return ['Department Report', $this->departmentData($date, (int) $validated['department_id'])];
            })(),
            'queue-performance' => ['Queue Performance Report', $this->queuePerformanceData($date)],
            'emergency' => ['Emergency Report', $this->emergencyData($date)],
        };

        $pdf = app('dompdf.wrapper')->loadView('reports.pdf', [
            'title' => $title,
            'date' => $date,
            'generatedAt' => now()->format('d M Y, H:i'),
            'data' => $data,
        ]);

        return $pdf->stream(str($title)->slug()."-{$date}.pdf");
    }
}
