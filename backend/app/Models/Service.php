<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['visit_id', 'previous_service_id', 'billing_for_service_id', 'department_id', 'doctor_id', 'service_type', 'status', 'notes', 'requires_payment'])]
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

    /** Only set on a Billing-queue service — the service the cashier ticket collects payment for (see BillingQueue). */
    public function billingFor(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'billing_for_service_id');
    }

    /**
     * Excludes Billing-queue services, so "the visit's current/latest
     * service" lookups keep meaning the clinical stop the patient is at.
     */
    public function scopeClinical($query)
    {
        return $query->whereNull('billing_for_service_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function requestedTests(): HasMany
    {
        return $this->hasMany(ServiceRequestedTest::class);
    }

    public function prescribedMedications(): HasMany
    {
        return $this->hasMany(ServicePrescribedMedication::class);
    }

    /**
     * The Laboratory checklist (service_requested_tests) is always stored
     * against the CONS service that actually filled it in. That's usually
     * one hop back from a LAB service (Laboratory Staff's view, Billing's
     * pre-selection) — but the Doctor's SECOND consultation, opened once
     * Laboratory forwards the patient back for review ("Result" on the
     * Laboratory Queue), is ALSO a CONS service, with no rows of its own
     * in service_requested_tests. A naive "any CONS service is its own
     * origin" check returned that empty second service, so the doctor saw
     * no lab answers at all. Walk back through previous_service_id — CONS
     * and LAB legitimately alternate across one lab round-trip — until
     * landing on a CONS service that actually owns rows. A fresh CONS
     * service about to be filled in for the very first time (no
     * previousService yet, or a request never sent to Laboratory) has
     * nowhere left to walk to, so it correctly stops on itself.
     */
    public function labRequestOriginService(): Service
    {
        $current = $this;
        $current->loadMissing('department', 'previousService');

        while (
            $current->previousService
            && ($current->department?->dept_code !== 'CONS' || $current->requestedTests()->doesntExist())
        ) {
            $current = $current->previousService;
            $current->loadMissing('department', 'previousService');
        }

        return $current;
    }

    /**
     * Same one-hop pattern as labRequestOriginService(), for the medication
     * checklist (service_prescribed_medications) — always stored against
     * the PRESCRIBING Consultation service. A Pharmacy service reached via
     * Laboratory Staff forwarding straight to Pharmacy (bypassing Doctor
     * review) has no Consultation service one hop back, so this simply
     * falls through to an empty checklist for that case, same as
     * labRequestOriginService() would.
     */
    public function pharmacyRequestOriginService(): Service
    {
        $this->loadMissing('department', 'previousService');

        return $this->department?->dept_code === 'CONS' ? $this : ($this->previousService ?? $this);
    }
}
