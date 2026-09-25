<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The traveling clinical record — one row per Visit (see ClinicalRecord),
 * accompanying a patient from Registration through Doctor, Laboratory,
 * and back. Each section is writable ONLY by the role responsible for
 * it — enforced here via SECTION_OWNERS, not just hidden in the UI — and
 * this whole controller REPLACES services.notes as the place lab results
 * get recorded (Phase 8's PATCH /services/{id}/notes still exists for
 * whatever else might use free-text service notes, but lab results now
 * live here instead, per this phase's explicit instruction not to run
 * two parallel notes systems).
 */
class ClinicalRecordController extends Controller
{
    /** @var array<string, string> field => the one role responsible for it (Administrator is always additionally allowed, matching every other per-role gate in this app — e.g. PaymentController, InsuranceCardController). */
    private const SECTION_OWNERS = [
        'chief_complaint' => 'Registration Staff',
        'doctor_symptoms_notes' => 'Doctor',
        'doctor_preliminary_diagnosis' => 'Doctor',
        'requested_tests_other' => 'Doctor',
        'referral_target' => 'Doctor',
        'temperature' => 'Doctor',
        'blood_pressure' => 'Doctor',
        'weight' => 'Doctor',
        'pulse_rate' => 'Doctor',
        'lab_results_notes' => 'Laboratory Staff',
        'final_diagnosis' => 'Doctor',
        'treatment_plan' => 'Doctor',
        'prescribed_medications_other' => 'Doctor',
        'patient_signature_name' => 'Doctor',
        'patient_signature_phone' => 'Doctor',
        'follow_up_date' => 'Doctor',
        'follow_up_instructions' => 'Doctor',
    ];

    public function show(Request $request, Visit $visit)
    {
        $this->assertCanView($request, $visit);

        $record = $visit->clinicalRecord()->firstOrCreate([]);

        return response()->json(['clinical_record' => $record->load('labTechnician:id,first_name,last_name,name')]);
    }

    public function update(Request $request, Visit $visit)
    {
        $user = $request->user();
        $record = $visit->clinicalRecord()->firstOrCreate([]);

        $submittedFields = array_intersect(array_keys($request->all()), array_keys(self::SECTION_OWNERS));

        abort_if(empty($submittedFields), 422, 'No recognized clinical record field was submitted.');

        foreach ($submittedFields as $field) {
            $ownerRole = self::SECTION_OWNERS[$field];
            abort_unless(
                $user->hasRole($ownerRole) || $user->hasRole('Administrator'),
                403,
                "Only {$ownerRole} (or an Administrator) may write '{$field}'."
            );
        }

        $data = $request->validate([
            'chief_complaint' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'doctor_symptoms_notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'doctor_preliminary_diagnosis' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'requested_tests_other' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'referral_target' => ['sometimes', 'nullable', Rule::in(['laboratory', 'pharmacy', 'refer', 'followup', 'none'])],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'between:30,45'],
            'blood_pressure' => ['sometimes', 'nullable', 'string', 'max:20'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'between:0,500'],
            'pulse_rate' => ['sometimes', 'nullable', 'integer', 'between:0,300'],
            'lab_results_notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'final_diagnosis' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'treatment_plan' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'prescribed_medications_other' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'patient_signature_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'patient_signature_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'follow_up_date' => ['sometimes', 'nullable', 'date'],
            'follow_up_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $updates = array_intersect_key($data, array_flip($submittedFields));

        // lab_technician_id is never client-writable (prevents one
        // Laboratory Staff member attributing results to another) — it's
        // derived from whoever is actually recording the results.
        if (array_key_exists('lab_results_notes', $updates) && $user->hasRole('Laboratory Staff')) {
            $updates['lab_technician_id'] = $user->id;
        }

        // The patient signs once both fields are actually filled — this is
        // the trigger point per Part 4.2, not a separately client-set flag.
        if (
            array_key_exists('patient_signature_name', $updates)
            && array_key_exists('patient_signature_phone', $updates)
            && filled($updates['patient_signature_name'])
            && filled($updates['patient_signature_phone'])
        ) {
            $updates['signed_at'] = now();
        }

        $record->update($updates);

        return response()->json(['clinical_record' => $record->fresh()->load('labTechnician:id,first_name,last_name,name')]);
    }

    /**
     * Reuses DepartmentRoles::userCanActOn against the visit's CURRENT
     * (latest) service's department — the same rule that already governs
     * "can this role act on this patient" everywhere else in the app —
     * plus Administrator, per the spec.
     */
    private function assertCanView(Request $request, Visit $visit): void
    {
        $user = $request->user();

        if ($user->hasRole('Administrator')) {
            return;
        }

        $currentService = $visit->services()->clinical()->latest()->with('department')->first();

        abort_unless(
            $currentService && DepartmentRoles::userCanActOn($user, $currentService->department->dept_code),
            403,
            'You are not permitted to view this patient\'s clinical record.'
        );
    }
}
