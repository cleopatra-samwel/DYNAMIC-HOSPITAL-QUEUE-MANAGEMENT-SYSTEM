<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;

/**
 * Backs Laboratory's "Test Results" page (updateNotes), plus the two
 * OnHold-resolution mechanisms alongside ServiceFlowController's
 * auto-resolve: an explicit staff action for the "never came back" case
 * (resolveHold), and a visibility listing so a forgotten hold doesn't go
 * unnoticed (openHolds).
 */
class ServiceController extends Controller
{
    public function updateNotes(Request $request, Service $service)
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        $service->loadMissing('department');

        abort_unless(
            DepartmentRoles::userCanActOn($request->user(), $service->department->dept_code),
            403,
            'You are not permitted to edit results for this department.'
        );

        $service->update(['notes' => $data['notes']]);

        return response()->json(['service' => $service->fresh()->load(['department', 'queueTicket', 'visit.patient'])]);
    }

    /**
     * Explicit staff resolution — the "never returns" case. Deliberately
     * NOT automatic: a Doctor expected to personally review this patient
     * again, so ending that expectation requires a human decision and a
     * recorded reason, not a side effect of some unrelated action.
     */
    public function resolveHold(Request $request, Service $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        abort_unless(
            $request->user()->hasAnyRole(['Doctor', 'Administrator']),
            403,
            'Only a Doctor or Administrator may resolve a held service.'
        );

        abort_unless(
            $service->status === 'OnHold',
            422,
            "This service is not OnHold (current status: {$service->status})."
        );

        $service->update(['status' => 'Cancelled']);

        $ticket = QueueTicket::where('service_id', $service->id)->first();
        if ($ticket) {
            QueueEvent::create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => 'HOLD_RESOLVED',
                'notes' => $data['reason'],
                'performed_by' => $request->user()->id,
                'event_time' => now(),
            ]);
        }

        return response()->json(['service' => $service->fresh()->load(['department', 'queueTicket', 'visit.patient'])]);
    }

    /**
     * Visibility: every OnHold service older than the given threshold
     * (default 2 hours), so a hold nobody resolved doesn't sit invisible
     * forever — surfaced on the Administrator dashboard.
     */
    public function openHolds(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Administrator only.');

        $hours = (int) $request->query('hours', 2);
        $cutoff = now()->subHours($hours);

        $holds = Service::where('status', 'OnHold')
            ->where('updated_at', '<=', $cutoff)
            ->with(['department', 'visit.patient', 'queueTicket'])
            ->orderBy('updated_at')
            ->get()
            ->map(fn (Service $service) => [
                'service_id' => $service->id,
                'visit_id' => $service->visit_id,
                'patient_name' => $service->visit?->patient?->name,
                'department' => $service->department?->dept_name,
                'ticket_number' => $service->queueTicket?->queue_number,
                'held_since' => $service->updated_at,
                'hours_open' => round($service->updated_at->diffInMinutes(now()) / 60, 1),
            ]);

        return response()->json(['holds' => $holds, 'threshold_hours' => $hours]);
    }

    /**
     * Same shape of problem as OnHold above, same fix: a doctor-specific
     * ticket (registration's optional "Preferred Doctor") that its doctor
     * never calls just sits WAITING, invisible to every OTHER doctor's
     * callNext() — this is the visibility half of that known limitation,
     * mirroring openHolds() exactly (a threshold, ordered oldest-first).
     * Only WAITING tickets are listed — once called, the doctor lock has
     * already done its job and stops being a "stuck" concern.
     */
    public function openDoctorLocks(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Administrator only.');

        $hours = (int) $request->query('hours', 2);
        $cutoff = now()->subHours($hours);

        $locks = Service::whereNotNull('doctor_id')
            ->whereHas('queueTicket', fn ($q) => $q->where('status', 'WAITING')->where('created_at', '<=', $cutoff))
            ->with(['department', 'doctor', 'visit.patient', 'queueTicket'])
            ->get()
            ->map(fn (Service $service) => [
                'service_id' => $service->id,
                'visit_id' => $service->visit_id,
                'patient_name' => $service->visit?->patient?->name,
                'department' => $service->department?->dept_name,
                'doctor_name' => $service->doctor?->fullName(),
                'ticket_number' => $service->queueTicket?->queue_number,
                'waiting_since' => $service->queueTicket?->created_at,
                'hours_waiting' => round($service->queueTicket->created_at->diffInMinutes(now()) / 60, 1),
            ])
            ->sortBy('waiting_since')
            ->values();

        return response()->json(['locks' => $locks, 'threshold_hours' => $hours]);
    }

    /**
     * Clears doctor_id back to null — the ticket immediately becomes
     * callable by any doctor's callNext() again (see PriorityEngine).
     * Administrator-only and requires a reason, mirroring resolveHold()'s
     * pattern exactly: this ends another doctor's expectation of
     * personally seeing this patient, so it's a deliberate, recorded
     * human decision, not a side effect of some unrelated action.
     */
    public function clearDoctor(Request $request, Service $service)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only an Administrator may clear a doctor assignment.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        abort_unless($service->doctor_id !== null, 422, 'This service has no doctor assigned.');

        $service->update(['doctor_id' => null]);

        $ticket = QueueTicket::where('service_id', $service->id)->first();
        if ($ticket) {
            QueueEvent::create([
                'queue_ticket_id' => $ticket->id,
                'event_type' => 'DOCTOR_UNASSIGNED',
                'notes' => $data['reason'],
                'performed_by' => $request->user()->id,
                'event_time' => now(),
            ]);
        }

        return response()->json(['service' => $service->fresh()->load(['department', 'doctor', 'queueTicket', 'visit.patient'])]);
    }
}
