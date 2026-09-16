<?php

namespace App\Support;

use App\Models\Patient;

/**
 * Extracted out of PatientController so CheckInController's
 * existing-patient-or-new-patient conversion path generates numbers the
 * exact same way as the regular "New Patient" form, rather than
 * duplicating the sequence logic.
 */
class PatientNumberGenerator
{
    public static function next(): string
    {
        $sequence = Patient::count() + 1;

        do {
            $candidate = 'P-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Patient::where('patient_number', $candidate)->exists());

        return $candidate;
    }
}
