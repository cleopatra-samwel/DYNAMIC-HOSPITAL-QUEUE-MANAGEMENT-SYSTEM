<?php

namespace App\Http\Controllers\Api;

use App\Events\PaymentVerified;
use App\Http\Controllers\Controller;
use App\Models\LabTestCatalog;
use App\Models\MedicationCatalog;
use App\Models\Payment;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Support\BillingQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — Cashier/Billing Staff verify payment/insurance per service.
 * A verified Payment row is what PaymentGate checks before a ticket may
 * move IN_SERVICE at Consultation, Laboratory, or Pharmacy.
 *
 * Itemized pricing (Part 6): a flat client-supplied 'amount' no longer
 * exists — every payment is built from one or more payment_items
 * (catalog_type lab_test|medication|other), and payments.amount is
 * always the SUM of those items (see Payment::recomputeAmount), never
 * set directly. This is purely how the total is BUILT — PaymentGate
 * itself still only ever checks payment.status === VERIFIED, completely
 * unaware itemization exists.
 */
class PaymentController extends Controller
{
    private function assertBillingStaff(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(['Cashier/Billing Staff', 'Administrator']),
            403,
            'Only Cashier/Billing Staff may verify payments.'
        );
    }

    public function verify(Request $request, Service $service)
    {
        $this->assertBillingStaff($request);

        $data = $request->validate([
            'method' => ['required', 'in:Cash,Insurance'],
            'insurance_provider' => ['required_if:method,Insurance', 'nullable', 'string', 'max:255'],
            'insurance_ref' => ['required_if:method,Insurance', 'nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.catalog_type' => ['required', Rule::in(['lab_test', 'medication', 'other'])],
            'items.*.catalog_item_id' => ['nullable', 'integer'],
            'items.*.custom_label' => ['nullable', 'string', 'max:255'],
            // Matches payment_items.amount's decimal(12,2) column — without
            // this cap, a mistyped amount (extra digits) doesn't fail
            // validation, it crashes with a raw Postgres "numeric field
            // overflow" 500 error instead (confirmed live in
            // storage/logs/laravel.log, 2026-09-13 04:39:59).
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        // Cross-table conditional checks (which catalog_item_id belongs to
        // which catalog depending on catalog_type) can't be expressed as a
        // single declarative rule above, so they're checked explicitly here.
        foreach ($data['items'] as $index => $item) {
            if ($item['catalog_type'] === 'other') {
                abort_if(blank($item['custom_label'] ?? null), 422, "Item #{$index}: a custom label is required when catalog_type is \"other\".");
                continue;
            }

            abort_if(empty($item['catalog_item_id']), 422, "Item #{$index}: catalog_item_id is required for catalog_type \"{$item['catalog_type']}\".");

            $exists = match ($item['catalog_type']) {
                'lab_test' => LabTestCatalog::whereKey($item['catalog_item_id'])->exists(),
                'medication' => MedicationCatalog::whereKey($item['catalog_item_id'])->exists(),
            };

            abort_unless($exists, 422, "Item #{$index}: catalog_item_id does not exist in the {$item['catalog_type']} catalog.");
        }

        $payment = DB::transaction(function () use ($service, $data, $request) {
            $payment = Payment::updateOrCreate(
                ['service_id' => $service->id],
                [
                    'method' => $data['method'],
                    'insurance_provider' => $data['insurance_provider'] ?? null,
                    'insurance_ref' => $data['insurance_ref'] ?? null,
                    'status' => 'VERIFIED',
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ]
            );

            // Re-verifying (e.g. correcting the item list) replaces the
            // previous items outright rather than appending duplicates.
            $payment->items()->delete();

            foreach ($data['items'] as $item) {
                $payment->items()->create([
                    'catalog_type' => $item['catalog_type'],
                    'catalog_item_id' => $item['catalog_type'] === 'other' ? null : $item['catalog_item_id'],
                    'custom_label' => $item['catalog_type'] === 'other' ? $item['custom_label'] : null,
                    'amount' => $item['amount'],
                ]);
            }

            $payment->recomputeAmount();

            return $payment;
        });

        // Paid: the cashier's Billing-queue ticket for this service is done.
        BillingQueue::complete($service, $request->user());

        PaymentVerified::dispatch($payment->fresh());

        return response()->json(['payment' => $payment->fresh()->load(['service.department', 'service.visit.patient', 'verifiedBy', 'items'])], 201);
    }

    /**
     * Every billable service (Consultation/Laboratory/Pharmacy) that
     * doesn't yet have a VERIFIED payment — across all three departments,
     * not just two — for the Cashier's Pending Payments queue.
     */
    public function pending(Request $request)
    {
        $this->assertBillingStaff($request);

        $services = Service::query()
            ->where('requires_payment', true)
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'VERIFIED'))
            ->whereNotIn('status', ['Cancelled'])
            ->with(['department', 'visit.patient', 'visit.clinicalRecord', 'previousService'])
            ->latest()
            ->get()
            ->map(function (Service $service) {
                // Reference context for Billing when picking catalog items
                // — what the Doctor requested (Laboratory) or prescribed
                // (Pharmacy). The checklist IDs double as a convenient
                // pre-selection default in the itemized picker — still
                // freely editable there, per the Structured Laboratory
                // Request Form spec.
                $requestedTestCatalogIds = [];
                if ($service->department?->dept_code === 'LAB') {
                    $origin = $service->labRequestOriginService();
                    $requestedTestCatalogIds = $origin->requestedTests()->pluck('lab_test_catalog_id')->all();
                }

                $prescribedMedicationCatalogIds = [];
                $prescribedMedicationsOther = null;
                if ($service->department?->dept_code === 'PHARM') {
                    $origin = $service->pharmacyRequestOriginService();
                    $prescribedMedicationCatalogIds = $origin->prescribedMedications()->pluck('medication_catalog_id')->all();
                    $prescribedMedicationsOther = $origin->visit?->clinicalRecord?->prescribed_medications_other;
                }

                // The Consultation service this payment traces back to — the
                // doctor's visit the Cashier Billing form summarises (who
                // saw the patient, and when).
                $consultation = match ($service->department?->dept_code) {
                    'CONS' => $service,
                    'LAB' => $service->labRequestOriginService(),
                    'PHARM' => $service->pharmacyRequestOriginService(),
                    default => null,
                };
                $consultationTicket = $consultation?->department?->dept_code === 'CONS' ? $consultation->queueTicket : null;
                $doctorName = $consultationTicket
                    ? QueueEvent::where('queue_ticket_id', $consultationTicket->id)
                        ->where('event_type', 'CALLED')
                        ->with('performedBy:id,name')
                        ->latest('event_time')
                        ->first()?->performedBy?->name
                    : null;

                return [
                    'service_id' => $service->id,
                    'visit_id' => $service->visit_id,
                    'patient_name' => $service->visit?->patient?->name,
                    'patient_number' => $service->visit?->patient?->patient_number,
                    'patient_contact' => $service->visit?->patient?->contact,
                    'doctor_name' => $doctorName,
                    'consulted_at' => $consultationTicket?->called_at ?? $consultationTicket?->created_at,
                    'department' => $service->department?->dept_name,
                    'dept_code' => $service->department?->dept_code,
                    'payment_method' => $service->visit?->payment_method,
                    'created_at' => $service->created_at,
                    'requested_test_catalog_ids' => $requestedTestCatalogIds,
                    'requested_tests_other' => $service->visit?->clinicalRecord?->requested_tests_other,
                    'prescribed_medication_catalog_ids' => $prescribedMedicationCatalogIds,
                    'prescribed_medications_other' => $prescribedMedicationsOther,
                    'final_diagnosis' => $service->visit?->clinicalRecord?->final_diagnosis,
                    'treatment_plan' => $service->visit?->clinicalRecord?->treatment_plan,
                ];
            });

        return response()->json(['pending' => $services]);
    }

    /** Backs the Billing dashboard's three summary cards. */
    public function stats(Request $request)
    {
        $this->assertBillingStaff($request);

        $pendingCount = Service::query()
            ->where('requires_payment', true)
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'VERIFIED'))
            ->whereNotIn('status', ['Cancelled'])
            ->count();

        $verifiedToday = Payment::where('status', 'VERIFIED')->whereDate('verified_at', now()->toDateString());

        return response()->json([
            'pending_payments' => $pendingCount,
            'cash_collected_today' => (float) (clone $verifiedToday)->where('method', 'Cash')->sum('amount'),
            'insurance_claims_verified_today' => (clone $verifiedToday)->where('method', 'Insurance')->count(),
            'waiting' => QueueTicket::where('status', 'WAITING')
                ->whereHas('service.department', fn ($q) => $q->where('dept_code', 'BILL'))
                ->count(),
        ]);
    }
}
