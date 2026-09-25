<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per checkbox a Doctor ticks on the medication prescription checklist — see PrescribedMedicationController. */
#[Fillable(['service_id', 'medication_catalog_id', 'dosage', 'frequency', 'duration', 'quantity', 'dispensed_quantity', 'dispensed_at'])]
class ServicePrescribedMedication extends Model
{
    protected $table = 'service_prescribed_medications';

    protected function casts(): array
    {
        return ['dispensed_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(MedicationCatalog::class, 'medication_catalog_id');
    }
}
