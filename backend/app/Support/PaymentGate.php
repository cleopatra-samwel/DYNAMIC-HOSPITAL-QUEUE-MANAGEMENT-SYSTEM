<?php

namespace App\Support;

use App\Models\Service;

/**
 * Phase 6: Consultation, Laboratory, and Pharmacy all require a verified
 * payment before a patient can be moved IN_SERVICE at that stage —
 * Registration and Billing themselves are never billable stops. This is
 * the one place that decides which departments are billable, so
 * VisitController/ServiceFlowController (service creation) and
 * TicketTransitionService (the gate itself) can't drift out of sync.
 */
class PaymentGate
{
    private const BILLABLE_DEPT_CODES = ['CONS', 'LAB', 'PHARM'];

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
