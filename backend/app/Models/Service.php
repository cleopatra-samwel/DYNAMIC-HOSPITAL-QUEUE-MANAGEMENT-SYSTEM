<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['visit_id', 'previous_service_id', 'department_id', 'doctor_id', 'service_type', 'status', 'notes', 'requires_payment'])]
class Service extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requires_payment' => 'boolean',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Only ever set for a Consultation-type service — see PriorityEngine::callNext()'s doctor-scoping. */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class);
    }

    public function previousService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'previous_service_id');
    }

    public function nextService(): HasOne
    {
        return $this->hasOne(Service::class, 'previous_service_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function requestedTests(): HasMany
    {
        return $this->hasMany(ServiceRequestedTest::class);
    }

    /**
     * The Laboratory checklist (service_requested_tests) is always stored
     * against the REQUESTING Consultation service, but Laboratory Staff's
     * view and Billing's pre-selection both start from a LAB service —
     * one hop back via previous_service_id is exactly the Consultation
     * service that forwarded them here (ServiceFlowController::store sets
     * this at creation time), so no deeper chain-walk is needed.
     */
    public function labRequestOriginService(): Service
    {
        $this->loadMissing('department', 'previousService');

        return $this->department?->dept_code === 'CONS' ? $this : ($this->previousService ?? $this);
    }
}
