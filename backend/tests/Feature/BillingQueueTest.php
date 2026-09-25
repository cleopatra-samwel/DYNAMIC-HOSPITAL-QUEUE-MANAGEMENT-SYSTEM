<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\Visit;

/**
 * Cashier/Billing has its own queue: every service that requires payment
 * gets a companion BILL ticket the cashier calls; verifying the payment
 * completes it; cancelling the paid-for service cancels it; and it never
 * leaks into the patient's clinical journey.
 */
class BillingQueueTest extends NotificationTestCase
{
    private Department $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bill = Department::create(['dept_code' => 'BILL', 'dept_name' => 'Billing', 'is_active' => true]);
    }

    /** @return array{0: Visit, 1: Service, 2: QueueTicket} */
    private function registerVisit(): array
    {
        $patientId = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Billing Queue Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000300',
        ])->assertStatus(201)->json('patient.id');

        $visitId = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientId,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201)->json('visit.id');

        $clinical = Service::where('visit_id', $visitId)->where('department_id', $this->cons->id)->firstOrFail();
        $billing = QueueTicket::whereHas('service', fn ($q) => $q->where('billing_for_service_id', $clinical->id))->firstOrFail();

        return [Visit::findOrFail($visitId), $clinical, $billing];
    }

    public function test_registering_a_billable_visit_opens_a_waiting_billing_ticket_that_stays_out_of_the_clinical_journey(): void
    {
        [$visit, $clinical, $billing] = $this->registerVisit();

        $this->assertSame('WAITING', $billing->status);
        $this->assertStringStartsWith('BILL-', $billing->queue_number);
        $this->assertSame($this->bill->id, $billing->service->department_id);
        $this->assertSame($clinical->id, $billing->service->billing_for_service_id);
        $this->assertFalse($billing->service->requires_payment);

        // The patient's tracking page still follows the Consultation ticket.
        $this->getJson("/api/track/{$visit->tracking_token}")
            ->assertOk()
            ->assertJsonPath('department', 'Consultation')
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

        $this->actingAs($cashier)->postJson("/api/services/{$clinical->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'Consultation fee', 'amount' => 5000]],
        ])->assertStatus(201);

        $this->assertSame('COMPLETED', $billing->fresh()->status);
        $this->assertSame('Completed', $billing->service->fresh()->status);
        // The visit is back at the clinical stop, not stuck at "in billing".
        $this->assertSame('WAITING_CONS', $visit->fresh()->overall_status);
    }

    public function test_pending_payments_carry_the_patient_and_doctor_details_shown_on_the_cashier_billing_form(): void
    {
        [, $clinical] = $this->registerVisit();
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$clinical->queueTicket->id}/call")->assertOk();

        $this->actingAs($this->makeUser('Cashier/Billing Staff'))->getJson('/api/payments/pending')
            ->assertOk()
            ->assertJsonPath('pending.0.patient_name', 'Billing Queue Patient')
            ->assertJsonPath('pending.0.patient_contact', '0700000300')
            ->assertJsonPath('pending.0.doctor_name', $this->doctor->name)
            ->assertJsonStructure(['pending' => [['patient_number', 'consulted_at', 'final_diagnosis']]]);
    }

    public function test_cancelling_the_paid_for_service_cancels_its_billing_ticket(): void
    {
        [, $clinical, $billing] = $this->registerVisit();

        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$clinical->queueTicket->id}/cancel")->assertOk();

        $this->assertSame('CANCELLED', $billing->fresh()->status);
        $this->assertSame('Cancelled', $billing->service->fresh()->status);
    }

    public function test_billing_queue_is_only_opened_for_services_that_require_payment(): void
    {
        $regDept = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);

        $patientId = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Reg Only Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000301',
        ])->assertStatus(201)->json('patient.id');

        $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientId, 'patient_type' => 'Normal', 'payment_method' => 'Cash', 'department_id' => $regDept->id,
        ])->assertStatus(201);

        $this->assertSame(0, Service::whereNotNull('billing_for_service_id')->count());
    }
}
