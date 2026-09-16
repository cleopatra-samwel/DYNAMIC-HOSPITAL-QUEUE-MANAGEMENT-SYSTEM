<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\PriorityLevel;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\ServiceRequestedTest;
use App\Models\Visit;
use App\Rules\ActiveDoctor;
use App\Services\NotificationService;
use App\Support\PaymentGate;
use App\Support\QueueJourney;
use App\Support\QueueNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — "request next service": opens a new Service + QueueTicket
 * (WAITING) for a visit in a target department, closing whichever service
 * is currently active (IN_SERVICE) for that visit first, atomically in
 * one transaction. Reuses QueueNumberGenerator (Phase 2/3) — ticket
 * numbering logic isn't duplicated here.
 *
 * Also the endpoint Registration Staff use for the ONE handoff out of a
 * self check-in's auto-created Registration ticket (see
 * VisitController::updateRegistrationDetails, called just before this) —
 * everywhere else, only clinical staff may open a new department stage.
 */
class ServiceFlowController extends Controller
{
    public function store(Request $request, Visit $visit)
    {
        $data = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            // If true, the current service is parked ON_HOLD instead of
            // Completed — for when the requesting staff member expects the
            // patient back on this same thread (e.g. a Doctor sending a
            // patient to Laboratory who will review results personally).
            // Forwarding back (Laboratory -> Doctor) always opens a brand
            // new Consultation service regardless — the held one is never
            // reopened.
            'hold_current' => ['sometimes', 'boolean'],
            // Only meaningful for Consultation — mirrors
            // VisitRegistrationService::createVisit's own doctor_id
            // handling, since Registration's REG->CONS handoff is now
            // the effective replacement for that original "preferred
            // doctor" step.
            'doctor_id' => ['nullable', 'integer', new ActiveDoctor()],
        ]);

        $targetDept = Department::findOrFail($data['department_id']);
        $holdCurrent = (bool) ($data['hold_current'] ?? false);

        // Registration is the entry point, never a destination — the first
        // forward out of it (see VisitController::updateRegistrationDetails)
        // must send the patient to an actual clinical/billing stop.
        abort_if($targetDept->dept_code === 'REG', 422, 'Cannot forward a visit back into Registration.');

        $currentTicketForAuth = QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('visit_id', $visit->id))
            ->where('status', 'IN_SERVICE')
            ->with('service.department')
            ->first();

        // Never a Patient, never automatic/bulk. Clinical staff may forward
        // from anywhere; Registration Staff may only complete the ONE
        // handoff out of their own department (the self-check-in ticket
        // this visit started with) — never from a clinical department's
        // in-progress service.
        $isClinicalStaff = $request->user()->hasAnyRole(['Doctor', 'Laboratory Staff', 'Pharmacy Staff', 'Administrator']);
        $isRegistrationHandoff = $request->user()->hasRole('Registration Staff')
            && $currentTicketForAuth?->service->department->dept_code === 'REG';

        abort_unless(
            $isClinicalStaff || $isRegistrationHandoff,
            403,
            'Only clinical staff may request a new service for a patient, or Registration Staff completing an initial registration.'
        );

        // The clinical record's content must exist before the patient is
        // forwarded, so the receiving department has something to act on
        // (Part 2.2/2.3/3.2/4.3 of the traveling clinical record spec) —
        // checked up front, before any DB writes, not partway through.
        $clinicalRecord = $visit->clinicalRecord()->firstOrCreate([]);

        if ($targetDept->dept_code === 'LAB') {
            $hasChecklistItem = $currentTicketForAuth
                && ServiceRequestedTest::where('service_id', $currentTicketForAuth->service->id)->exists();

            abort_if(
                ! $hasChecklistItem && blank($clinicalRecord->requested_tests_other),
                422,
                'At least one test must be selected (or noted under Others) before forwarding to Laboratory.'
            );
        }

        if ($targetDept->dept_code === 'PHARM') {
            abort_unless(
                $clinicalRecord->isReadyForPharmacy(),
                422,
                'The clinical record must have a final diagnosis, treatment plan, and patient signature (name and phone) before forwarding to Pharmacy.'
            );
        }

        // Laboratory cannot forward an empty result back to Doctor.
        if ($currentTicketForAuth && $currentTicketForAuth->service->department->dept_code === 'LAB' && $targetDept->dept_code === 'CONS') {
            abort_if(
                blank($clinicalRecord->lab_results_notes),
                422,
                'Lab results notes must be recorded before forwarding back to Doctor.'
            );
        }

        $newService = DB::transaction(function () use ($visit, $targetDept, $holdCurrent, $request, $data) {
            $currentTicket = QueueTicket::query()
                ->whereHas('service', fn ($q) => $q->where('visit_id', $visit->id))
                ->where('status', 'IN_SERVICE')
                ->lockForUpdate()
                ->first();

            if ($currentTicket) {
                $currentTicket->loadMissing('service.department');
                $currentTicket->update(['status' => 'COMPLETED', 'completed_at' => now()]);
                $currentTicket->service->update(['status' => $holdCurrent ? 'OnHold' : 'Completed']);

                QueueEvent::create([
                    'queue_ticket_id' => $currentTicket->id,
                    'event_type' => 'COMPLETED',
                    'performed_by' => $request->user()->id,
                    'event_time' => now(),
                ]);
            }

            // Auto-resolve (safe, unambiguous case only): the patient is
            // being routed back into the SAME department where an earlier
            // service was left OnHold for this visit — that department is
            // handling them again, so the hold is naturally satisfied. Any
            // OTHER OnHold service (a different department, or "they never
            // came back") is left alone; it must be resolved explicitly via
            // ServiceController::resolveHold, never silently here.
            $resolvedHolds = Service::where('visit_id', $visit->id)
                ->where('department_id', $targetDept->id)
                ->where('status', 'OnHold')
                ->lockForUpdate()
                ->get();

            foreach ($resolvedHolds as $held) {
                $held->update(['status' => 'Completed']);

                if ($heldTicket = QueueTicket::where('service_id', $held->id)->first()) {
                    QueueEvent::create([
                        'queue_ticket_id' => $heldTicket->id,
                        'event_type' => 'HOLD_AUTO_RESOLVED',
                        'notes' => "Resolved automatically — visit routed back into {$targetDept->dept_name}.",
                        'performed_by' => $request->user()->id,
                        'event_time' => now(),
                    ]);
                }
            }

            $newService = Service::create([
                'visit_id' => $visit->id,
                'previous_service_id' => $currentTicket?->service->id,
                'department_id' => $targetDept->id,
                'doctor_id' => $targetDept->dept_code === 'CONS' ? ($data['doctor_id'] ?? null) : null,
                'service_type' => $targetDept->dept_name,
                'status' => 'Pending',
                'requires_payment' => PaymentGate::requiresPayment($targetDept->dept_code),
            ]);

            $priorityLevel = PriorityLevel::where(
                'name',
                $visit->patient_type === 'Emergency' ? 'Critical' : 'Normal'
            )->firstOrFail();

            $newTicket = QueueTicket::create([
                'service_id' => $newService->id,
                'priority_level_id' => $priorityLevel->id,
                'queue_number' => QueueNumberGenerator::next($targetDept),
                'priority_score' => $priorityLevel->weight,
                'status' => 'WAITING',
            ]);

            QueueEvent::create([
                'queue_ticket_id' => $newTicket->id,
                'event_type' => 'CREATED',
                'performed_by' => $request->user()->id,
                'event_time' => now(),
            ]);

            $visit->update([
                'overall_status' => QueueJourney::stateFor($targetDept->dept_code, 'WAITING'),
            ]);

            // TRANSFERRED is patient-facing notification-worthy per the spec
            // ("on a new Service created for the same visit") — no broadcast
            // event covers service creation, so this is a direct call here,
            // same reasoning as VisitController's WAITING notification. Only
            // meaningful when there WAS a prior department to transfer from;
            // falls back to a plain WAITING notification for the (unusual)
            // case of no active ticket to transfer from.
            if ($currentTicket) {
                app(NotificationService::class)->notifyTransferred($newTicket, $currentTicket->service->department);
            } else {
                app(NotificationService::class)->notifyWaiting($newTicket);
            }

            return $newService;
        });

        return response()->json([
            'service' => $newService->load(['department', 'queueTicket.priorityLevel']),
        ], 201);
    }
}
