<?php

namespace App\Support;

use App\Models\Service;

/**
 * Payment is taken ONCE, at the end of the patient's journey: Pharmacy is
 * the only billable stop, so a patient is seen (Consultation) and tested
 * (Laboratory) first and pays for the tests and medicines before Pharmacy
 * may call them or dispense. Registration, Consultation, Laboratory and
 * Billing themselves are never billable stops. This is
 * the one place that decides which departments are billable, so
 * VisitController/ServiceFlowController (service creation) and
 * TicketTransitionService (the gate itself) can't drift out of sync.
 */
class PaymentGate
{
    private const BILLABLE_DEPT_CODES = ['PHARM'];

    public static function requiresPayment(string $deptCode): bool
    {
        return in_array($deptCode, self::BILLABLE_DEPT_CODES, true);
    }

    /** True if the service doesn't need payment, or already has a verified one. */
    public static function passes(Service $service): bool
    {
        if (! $service->requires_payment) {
            return true;
        }

        $service->loadMissing('payment');

        return $service->payment !== null && $service->payment->status === 'VERIFIED';
    }
}
