<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\Visit;

/**
 * Payment is taken ONCE, before Pharmacy. Every Pharmacy service gets a
 * companion BILL ticket the cashier calls; verifying the payment completes
 * it and unlocks Pharmacy (which cannot call an unpaid patient);
 * cancelling the Pharmacy service cancels it; and it never leaks into the
 * patient's clinical journey. Consultation and Laboratory need no payment.
 */
class BillingQueueTest extends NotificationTestCase
{
    private Department $bill;

    private Department $pharm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bill = Department::create(['dept_code' => 'BILL', 'dept_name' => 'Billing', 'is_active' => true]);
        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true]);
    }

    /** @return array{0: Visit, 1: Service, 2: QueueTicket} */
    private function registerVisit(?Department $department = null): array
    {
        $department ??= $this->pharm;

        $patientId = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Billing Queue Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000300',
        ])->assertStatus(201)->json('patient.id');

        $visitId = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientId,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $department->id,
        ])->assertStatus(201)->json('visit.id');

        $clinical = Service::where('visit_id', $visitId)->where('department_id', $department->id)->firstOrFail();
        $billing = QueueTicket::whereHas('service', fn ($q) => $q->where('billing_for_service_id', $clinical->id))->first();

        return [Visit::findOrFail($visitId), $clinical, $billing];
    }

    private function verifyCash($cashier, Service $service): void
    {
        $this->actingAs($cashier)->postJson("/api/services/{$service->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'Medicines', 'amount' => 5000]],
        ])->assertStatus(201);
    }

    public function test_a_pharmacy_service_opens_a_waiting_billing_ticket_that_stays_out_of_the_clinical_journey(): void
    {
        [$visit, $clinical, $billing] = $this->registerVisit();

        $this->assertNotNull($billing);
        $this->assertSame('WAITING', $billing->status);
        $this->assertStringStartsWith('BILL-', $billing->queue_number);
        $this->assertSame($this->bill->id, $billing->service->department_id);
        $this->assertSame($clinical->id, $billing->service->billing_for_service_id);
        $this->assertFalse($billing->service->requires_payment);

        // The patient's tracking page still follows the Pharmacy ticket.
        $this->getJson("/api/track/{$visit->tracking_token}")
            ->assertOk()
            ->assertJsonPath('department', 'Pharmacy')
            ->assertJsonPath('queue_number', $clinical->queueTicket->queue_number);
    }

    public function test_cashier_calls_the_billing_ticket_and_verifying_payment_completes_it(): void
    {
        [$visit, $clinical, $billing] = $this->registerVisit();
        $cashier = $this->makeUser('Cashier/Billing Staff');

        $this->actingAs($cashier)->postJson("/api/departments/{$this->bill->id}/call-next")
            ->assertOk()
            ->assertJsonPath('ticket.id', $billing->id);
        $this->assertSame('CALLED', $billing->fresh()->status);

        $this->actingAs($cashier)->getJson('/api/payments/stats')->assertOk()->assertJsonPath('waiting', 0);

        $this->verifyCash($cashier, $clinical);

        $this->assertSame('COMPLETED', $billing->fresh()->status);
        $this->assertSame('Completed', $billing->service->fresh()->status);
        // The visit is back at the clinical stop, not stuck at "in billing".
        $this->assertSame('WAITING_PHARM', $visit->fresh()->overall_status);
    }

    public function test_pharmacy_cannot_call_a_patient_until_payment_is_verified(): void
    {
        [, $clinical] = $this->registerVisit();
        $pharmacist = $this->makeUser('Pharmacy Staff');
        $cashier = $this->makeUser('Cashier/Billing Staff');
        $ticketId = $clinical->queueTicket->id;

        // Manual call and call-next both refuse an unpaid patient.
        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticketId}/call")->assertStatus(402);
        $this->actingAs($pharmacist)->postJson("/api/departments/{$this->pharm->id}/call-next")
            ->assertOk()
            ->assertJsonPath('ticket', null);
        $this->assertSame('WAITING', $clinical->queueTicket->fresh()->status);

        $this->verifyCash($cashier, $clinical);

        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticketId}/call")->assertOk();
        $this->assertSame('CALLED', $clinical->queueTicket->fresh()->status);
    }

    public function test_consultation_and_laboratory_no_longer_wait_on_payment(): void
    {
        [, $clinical, $billing] = $this->registerVisit($this->cons);

        $this->assertNull($billing, 'A Consultation service has no billing ticket — payment happens once, before Pharmacy.');
        $this->assertFalse($clinical->fresh()->requires_payment);

        $ticketId = $clinical->queueTicket->id;
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticketId}/call")->assertOk();
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticketId}/start-service")->assertOk();
    }

    public function test_pending_payments_carry_the_patient_details_and_prescription_shown_on_the_cashier_billing_form(): void
    {
        [, $clinical] = $this->registerVisit();

        $this->actingAs($this->makeUser('Cashier/Billing Staff'))->getJson('/api/payments/pending')
            ->assertOk()
            ->assertJsonPath('pending.0.patient_name', 'Billing Queue Patient')
            ->assertJsonPath('pending.0.patient_contact', '0700000300')
            ->assertJsonPath('pending.0.service_id', $clinical->id)
            ->assertJsonStructure(['pending' => [['patient_number', 'consulted_at', 'final_diagnosis', 'prescribed_medications', 'requested_test_catalog_ids']]]);
    }

    public function test_cancelling_the_pharmacy_service_cancels_its_billing_ticket(): void
    {
        [, $clinical, $billing] = $this->registerVisit();

        $this->actingAs($this->makeUser('Pharmacy Staff'))->patchJson("/api/queue-tickets/{$clinical->queueTicket->id}/cancel")->assertOk();

        $this->assertSame('CANCELLED', $billing->fresh()->status);
        $this->assertSame('Cancelled', $billing->service->fresh()->status);
    }

    public function test_billing_queue_is_only_opened_for_services_that_require_payment(): void
    {
        $regDept = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);

        $this->registerVisit($regDept);

        $this->assertSame(0, Service::whereNotNull('billing_for_service_id')->count());
    }
}
