<?php

namespace Tests\Concerns;

use App\Models\Payment;
use App\Models\Service;

/**
 * Phase 6 made Consultation billable alongside Laboratory/Pharmacy, so any
 * test that moves a ticket to IN_SERVICE at one of those three departments
 * needs a VERIFIED payment on record first, or PaymentGate rejects it with
 * 402 — exactly as it should. This creates that state directly (bypassing
 * the HTTP verify endpoint, which has its own dedicated test coverage in
 * PaymentInsuranceTest) so other tests can stay focused on what they're
 * actually testing.
 */
trait VerifiesPayments
{
    protected function verifyPayment(Service $service, string $method = 'Cash'): Payment
    {
        return Payment::create([
            'service_id' => $service->id,
            'method' => $method,
            'amount' => $method === 'Cash' ? 1000 : null,
            'insurance_provider' => $method === 'Insurance' ? 'Test Insurer' : null,
            'insurance_ref' => $method === 'Insurance' ? 'REF-'.uniqid() : null,
            'status' => 'VERIFIED',
            'verified_at' => now(),
        ]);
    }
}
