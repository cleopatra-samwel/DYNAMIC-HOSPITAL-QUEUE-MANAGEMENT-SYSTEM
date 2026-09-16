<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Patient;
use Illuminate\Support\Facades\Log;

/**
 * Self check-in — a walk-in patient submits the public form with no auth
 * and is immediately placed into Registration's own queue with a real
 * ticket (Patient + Visit + Service[REG] + QueueTicket), exactly like any
 * other department — not left sitting in an un-ticketed physical-order
 * list. The check_ins row is kept purely as an audit trail of what was
 * submitted, marked CONVERTED in the same request it's created in.
 * Registration Staff complete the actual registration paperwork (payment,
 * category, target department) only once this ticket is called — see
 * VisitControllerRegistrationDetailsTest and ServiceFlowController.
 */
class CheckInTest extends NotificationTestCase
{
    protected Department $reg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reg = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);
    }

    public function test_public_can_submit_a_check_in_with_no_authentication_and_is_placed_directly_into_the_registration_queue(): void
    {
        $response = $this->postJson('/api/check-ins', [
            'name' => 'Walk-in Patient',
            'date_of_birth' => '1992-05-10',
            'gender' => 'Female',
            'contact' => '0700111222',
        ])->assertStatus(201);

        $this->assertNotEmpty($response->json('queue_number'));
        $this->assertNotEmpty($response->json('tracking_token'));

        $this->assertDatabaseHas('check_ins', [
            'id' => $response->json('check_in.id'),
            'name' => 'Walk-in Patient',
            'status' => 'CONVERTED',
        ]);

        $this->assertDatabaseHas('patients', ['contact' => '0700111222', 'name' => 'Walk-in Patient']);

        $this->assertDatabaseHas('services', [
            'department_id' => $this->reg->id,
        ]);

        $this->assertDatabaseHas('queue_tickets', [
            'queue_number' => $response->json('queue_number'),
            'status' => 'WAITING',
        ]);
    }

    public function test_self_check_in_with_a_contact_matching_an_existing_patient_links_instead_of_duplicating(): void
    {
        $existing = Patient::create([
            'patient_number' => 'P-9001', 'name' => 'Existing Patient', 'date_of_birth' => '1975-06-20', 'gender' => 'Female', 'contact' => '0700555666',
        ]);

        $patientCountBefore = Patient::count();

        $response = $this->postJson('/api/check-ins', [
            'name' => 'Existing Patient (self-reported)',
            'date_of_birth' => '1975-06-20',
            'gender' => 'Female',
            'contact' => '0700555666',
        ])->assertStatus(201);

        $this->assertSame($patientCountBefore, Patient::count(), 'No new Patient row should have been created for a matching contact.');

        $checkIn = \App\Models\CheckIn::find($response->json('check_in.id'));
        $this->assertDatabaseHas('visits', ['id' => $checkIn->visit_id, 'patient_id' => $existing->id]);
    }

    /** Registration Staff can act on this ticket exactly like any other department's — no special-casing needed. */
    public function test_registration_staff_can_call_the_new_registration_ticket(): void
    {
        $response = $this->postJson('/api/check-ins', [
            'name' => 'Callable Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700222333',
        ])->assertStatus(201);

        $ticket = \App\Models\QueueTicket::where('queue_number', $response->json('queue_number'))->firstOrFail();

        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/queue-tickets/{$ticket->id}/call")
            ->assertStatus(200)
            ->assertJsonPath('ticket.status', 'CALLED');
    }

    /** DoD-equivalent: the tracking-link SMS reads "Ticket number {X}. Please proceed to Counter number {Y}." from the very first (Registration) ticket. */
    public function test_check_in_sends_an_sms_with_the_registration_ticket_and_counter_number(): void
    {
        $this->reg->update(['counter_number' => 1]);

        Log::spy();

        $response = $this->postJson('/api/check-ins', [
            'name' => 'SMS Check', 'date_of_birth' => '1995-02-02', 'gender' => 'Male', 'contact' => '0700999000',
        ])->assertStatus(201);

        $queueNumber = $response->json('queue_number');
        $expectedPrefix = "Ticket number {$queueNumber}. Please proceed to Counter number 1 when called.";

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === '[SMS stub] Would send tracking link'
                && str_starts_with($context['message'], $expectedPrefix)
            );
    }
}
