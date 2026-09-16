<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Department;
use App\Models\Patient;
use App\Services\VisitRegistrationService;
use App\Support\PatientNumberGenerator;
use Illuminate\Http\Request;

/**
 * Self check-in — a walk-in patient submits name/DOB/gender/contact
 * themselves (no auth), and is immediately placed into Registration's own
 * queue with a real ticket — exactly like any other department, so they
 * appear on the queue/display board and can be called, instead of sitting
 * in an un-ticketed physical-order list. Registration Staff completes the
 * actual registration paperwork (payment, category, target department)
 * only once that ticket is called — see
 * VisitController::updateRegistrationDetails and
 * ServiceFlowController::store for that step.
 *
 * The check_ins row itself is kept purely as an audit trail of what the
 * patient originally submitted — it's marked CONVERTED in the same
 * request, never sits PENDING.
 */
class CheckInController extends Controller
{
    /** Public — no auth, reached via the static entrance QR code. */
    public function store(Request $request, VisitRegistrationService $registrationService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'gender' => ['required', 'string', 'in:Male,Female,Other'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $checkIn = CheckIn::create($data + ['status' => 'PENDING']);

        // Same contact-match dedup rule as always — a returning patient's
        // re-typed name/DOB/gender are discarded in favor of their
        // existing record rather than overwriting it with possibly
        // slightly different self-reported data.
        $patient = Patient::where('contact', $data['contact'])->first();

        if (! $patient) {
            $patient = Patient::create([
                'name' => $data['name'],
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'],
                'contact' => $data['contact'],
                'patient_number' => PatientNumberGenerator::next(),
            ]);
        }

        $registrationDept = Department::where('dept_code', 'REG')->firstOrFail();

        // No staff member is involved in this step — patient_type/
        // payment_method are just placeholders Registration Staff will
        // confirm or correct once this ticket is called (see
        // VisitController::updateRegistrationDetails); Registration never
        // requires payment either way (PaymentGate::requiresPayment).
        $visit = $registrationService->createVisit($patient, [
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $registrationDept->id,
        ], null);

        $checkIn->update(['status' => 'CONVERTED', 'visit_id' => $visit->id]);

        $ticket = $visit->services->first()->queueTicket;

        return response()->json([
            'check_in' => $checkIn,
            'queue_number' => $ticket->queue_number,
            'tracking_token' => $visit->tracking_token,
        ], 201);
    }
}
