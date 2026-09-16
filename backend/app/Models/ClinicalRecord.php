<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per Visit (see the unique constraint on visit_id) — accompanies a
 * patient from Registration through Doctor, Laboratory, and back, each
 * role's section written only by that role (enforced in
 * ClinicalRecordController, not just hidden in the UI — see
 * ClinicalRecordController::SECTION_OWNERS).
 */
#[Fillable([
    'visit_id',
    'chief_complaint',
    'doctor_symptoms_notes', 'doctor_preliminary_diagnosis', 'requested_tests_other', 'referral_target',
    'lab_results_notes', 'lab_technician_id',
    'final_diagnosis', 'treatment_plan', 'patient_signature_name', 'patient_signature_phone', 'signed_at',
])]
class ClinicalRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function labTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lab_technician_id');
    }

    /** "Send to Pharmacy" (Part 2.3/3.3/4.3) is gated on this — final diagnosis, treatment plan, and both signature fields must all be present. */
    public function isReadyForPharmacy(): bool
    {
        return filled($this->final_diagnosis)
            && filled($this->treatment_plan)
            && filled($this->patient_signature_name)
            && filled($this->patient_signature_phone);
    }
}
