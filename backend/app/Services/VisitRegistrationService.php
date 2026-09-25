<?php

namespace App\Services;

use App\Models\ClinicalRecord;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\BillingQueue;
use App\Support\PaymentGate;
use App\Support\QueueJourney;
use App\Support\QueueNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extracted out of VisitController::store so the exact same
 * Visit+Service+QueueTicket creation path (and the SMS sent afterward) is
 * shared by the regular "New Patient" flow and the check-in-conversion
 * flow (CheckInController::convert) — one source of truth, so neither
 * path can silently drift from the other.
 */
class VisitRegistrationService
{
    /**
     * @param  array{patient_type:string,payment_method:string,insurance_provider?:?string,insurance_ref?:?string,department_id:int,doctor_id?:?int,chief_complaint?:?string}  $data
     */
    public function createVisit(Patient $patient, array $data, ?User $actingUser): Visit
    {
        $department = Department::findOrFail($data['department_id']);

        // Only meaningful for Consultation — a doctor_id submitted for any
        // other department (shouldn't happen via the real form, since the
        // dropdown only appears for Consultation, but this is the one
        // place both registration paths funnel through) is silently
        // dropped rather than stored as nonsensical data.
        $doctorId = $department->dept_code === 'CONS' ? ($data['doctor_id'] ?? null) : null;

        $visit = DB::transaction(function () use ($patient, $data, $department, $doctorId, $actingUser) {
            $visit = Visit::create([
                'patient_id' => $patient->id,
                'visit_date' => now()->toDateString(),
                'patient_type' => $data['patient_type'],
                'emergency_confirmed' => false,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'Pending',
                'insurance_provider' => $data['insurance_provider'] ?? null,
                'insurance_ref' => $data['insurance_ref'] ?? null,
                'overall_status' => QueueJourney::stateFor($department->dept_code, 'WAITING'),
            ]);

            // One clinical_records row per visit, created here so BOTH
            // registration paths (the regular form and check-in
            // conversion) get it identically — chief_complaint is the
            // only field Registration is ever allowed to write, and only
            // if the patient actually mentioned one.
            ClinicalRecord::create([
                'visit_id' => $visit->id,
                'chief_complaint' => $data['chief_complaint'] ?? null,
            ]);

            $service = Service::create([
                'visit_id' => $visit->id,
                'department_id' => $department->id,
                'doctor_id' => $doctorId,
                'service_type' => $department->dept_name,
                'status' => 'Pending',
                'requires_payment' => PaymentGate::requiresPayment($department->dept_code),
            ]);

            $priorityLevel = PriorityLevel::where(
                'name',
                $data['patient_type'] === 'Emergency' ? 'Critical' : 'Normal'
            )->firstOrFail();

            $queueTicket = QueueTicket::create([
                'service_id' => $service->id,
                'priority_level_id' => $priorityLevel->id,
                'queue_number' => QueueNumberGenerator::next($department),
                'priority_score' => $priorityLevel->weight,
                'status' => 'WAITING',
            ]);

            QueueEvent::create([
                'queue_ticket_id' => $queueTicket->id,
                'event_type' => 'CREATED',
                // Null for self check-in — no staff member is involved yet
                // (see CheckInController::store, a public, unauthenticated
                // endpoint) — nullable by design (performed_by is a
                // nullOnDelete foreign key), not a workaround.
                'performed_by' => $actingUser?->id,
                'event_time' => now(),
            ]);

            // WAITING is patient-facing notification #1 — no broadcast event
            // covers ticket/service creation (only status transitions and
            // callNext do), so this is a direct call here.
            app(NotificationService::class)->notifyWaiting($queueTicket);

            BillingQueue::open($service, $visit, $actingUser);

            return $visit;
        });

        // Best-effort: an SMS delivery hiccup must never undo a completed
        // registration, so this runs after the transaction commits and
        // failures are swallowed (the stub itself just logs for now, but a
        // real gateway call added later could throw on a network error).
        try {
            app(NotificationService::class)->sendTrackingLink($visit);
        } catch (\Throwable $e) {
            Log::error('SMS tracking link delivery failed during registration — visit was still created successfully.', [
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'exception' => $e->getMessage(),
            ]);
            report($e);
        }

        return $visit;
    }
}
