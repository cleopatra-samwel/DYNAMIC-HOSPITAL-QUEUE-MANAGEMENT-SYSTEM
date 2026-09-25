<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\QueueTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

class MultiDepartmentFlowTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $lab;

    private Department $pharm;

    private User $registrationStaff;

    private User $doctor;

    private User $labStaff;

    private User $pharmacyStaff;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true]);

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');
        $this->pharmacyStaff = $this->makeUser('Pharmacy Staff');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * DoD #1: Registration -> Doctor -> Laboratory -> Doctor (2nd time) ->
     * Pharmacy -> Visit COMPLETED, with correct status at every step.
     */
    public function test_full_patient_walkthrough_across_departments(): void
    {
        $patientRes = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Walkthrough Patient',
            'date_of_birth' => '1985-06-15',
            'gender' => 'Male',
            'contact' => '0700000001',
        ])->assertStatus(201);
        $patientId = $patientRes->json('patient.id');

        $visitRes = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientId,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $visitId = $visitRes->json('visit.id');
        $consTicketId = $visitRes->json('visit.services.0.queue_ticket.id');
        $this->assertSame('WAITING_CONS', $visitRes->json('visit.overall_status'));

        // Phase 6: Consultation is now billable too — start-service is
        // blocked (402) without a verified payment first.
        $this->verifyPayment(Service::find($visitRes->json('visit.services.0.id')));

        // --- Doctor: call + start-service the consultation ticket ---
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/start-service")
            ->assertStatus(200)->assertJsonPath('ticket.status', 'IN_SERVICE');
        $this->assertSame('IN_CONS', Visit::find($visitId)->overall_status);
        $this->assertSame('Active', Service::find($visitRes->json('visit.services.0.id'))->status);

        // Forwarding to Laboratory requires requested_tests filled first.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'requested_tests_other' => 'Full blood count.',
        ])->assertStatus(200);

        // --- Doctor requests Laboratory next ---
        $labServiceRes = $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visitId}/services", ['department_id' => $this->lab->id])
            ->assertStatus(201);

        $this->assertSame('COMPLETED', QueueTicket::find($consTicketId)->status);
        $this->assertSame('WAITING_LAB', Visit::find($visitId)->overall_status);
        $labTicketId = $labServiceRes->json('service.queue_ticket.id');
        $this->assertSame('WAITING', $labServiceRes->json('service.queue_ticket.status'));

        $this->verifyPayment(Service::find($labServiceRes->json('service.id')));

        // --- Laboratory Staff: call + start-service, enter results ---
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/call")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/start-service")
            ->assertStatus(200)->assertJsonPath('ticket.status', 'IN_SERVICE');
        $this->assertSame('IN_LAB', Visit::find($visitId)->overall_status);

        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/complete", ['notes' => 'All normal.'])
            ->assertStatus(200)->assertJsonPath('ticket.status', 'COMPLETED');
        // Completing a ticket must NOT by itself close the visit — more
        // services are still coming.
        $this->assertNotSame('COMPLETED', Visit::find($visitId)->overall_status);

        // Forwarding back to Doctor requires lab_results_notes filled first.
        $this->actingAs($this->labStaff)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'lab_results_notes' => 'All normal.',
        ])->assertStatus(200);

        // --- Laboratory forwards back to the Doctor: brand new Consultation service ---
        $cons2Res = $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visitId}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);

        $this->assertSame('WAITING_CONS', Visit::find($visitId)->overall_status);
        $cons2TicketId = $cons2Res->json('service.queue_ticket.id');
        $cons2ServiceId = $cons2Res->json('service.id');
        $firstConsServiceId = $visitRes->json('visit.services.0.id');
        $this->assertNotSame($firstConsServiceId, $cons2ServiceId, 'A second, brand-new Consultation service must be created — the first is never reopened.');
        $this->assertSame(2, Service::where('visit_id', $visitId)->where('department_id', $this->cons->id)->count());

        $this->verifyPayment(Service::find($cons2ServiceId));

        // --- Doctor reviews results: call + start-service the second consultation ticket ---
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$cons2TicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$cons2TicketId}/start-service")
            ->assertStatus(200)->assertJsonPath('ticket.status', 'IN_SERVICE');
        $this->assertSame('IN_CONS', Visit::find($visitId)->overall_status);

        // Forwarding to Pharmacy requires the final review fields filled first.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'final_diagnosis' => 'Resolved.',
            'treatment_plan' => 'Course of medication.',
            'patient_signature_name' => 'Walkthrough Patient',
            'patient_signature_phone' => '0700000001',
        ])->assertStatus(200);

        // --- Doctor requests Pharmacy next ---
        $pharmRes = $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visitId}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(201);

        $this->assertSame('WAITING_PHARM', Visit::find($visitId)->overall_status);
        $pharmTicketId = $pharmRes->json('service.queue_ticket.id');

        $this->verifyPayment(Service::find($pharmRes->json('service.id')));

        // --- Pharmacy: call, start-service, complete ---
        $this->actingAs($this->pharmacyStaff)->patchJson("/api/queue-tickets/{$pharmTicketId}/call")->assertStatus(200);
        $this->actingAs($this->pharmacyStaff)->patchJson("/api/queue-tickets/{$pharmTicketId}/start-service")
            ->assertStatus(200)->assertJsonPath('ticket.status', 'IN_SERVICE');
        $this->assertSame('IN_PHARM', Visit::find($visitId)->overall_status);

        // The patient signs to confirm receiving their medicines before dispensing can be completed.
        $this->actingAs($this->pharmacyStaff)->patchJson("/api/visits/{$visitId}/clinical-record", ['dispensing_signature' => 'data:image/png;base64,iVBORw0KGgo='])->assertStatus(200);

        $this->actingAs($this->pharmacyStaff)->patchJson("/api/queue-tickets/{$pharmTicketId}/complete", ['notes' => 'Dispensed.'])
            ->assertStatus(200)->assertJsonPath('ticket.status', 'COMPLETED');
        $this->assertNotSame('COMPLETED', Visit::find($visitId)->overall_status, 'Ticket completion alone must not close the visit.');

        // --- Explicit visit closure: now every service is Completed, so it succeeds ---
        $this->actingAs($this->pharmacyStaff)->patchJson("/api/visits/{$visitId}/complete")
            ->assertStatus(200)->assertJsonPath('visit.overall_status', 'COMPLETED');

        $this->assertSame(4, Service::where('visit_id', $visitId)->count());
    }

    /**
     * DoD #2: forcing a patient's ticket to IN_SERVICE in a second
     * department while another of their services is still ACTIVE must be
     * rejected by the API directly.
     */
    public function test_a_visit_cannot_be_in_service_in_two_departments_at_once(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Double Booked Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000002']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        $consService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Active', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $consService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-TEST', 'priority_score' => 10, 'status' => 'IN_SERVICE']);

        $labService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);
        $labTicket = QueueTicket::create(['service_id' => $labService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'LAB-TEST', 'priority_score' => 10, 'status' => 'CALLED']);

        // This ticket also fails the Payment Gate (never verified) — the 409
        // short-circuits before that check runs, but its body must still
        // surface the payment problem so staff see both issues at once.
        $this->actingAs($this->labStaff)
            ->patchJson("/api/queue-tickets/{$labTicket->id}/start-service")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This patient already has an active service in another department.')
            ->assertJsonPath('payment_status', 'unpaid');

        $this->assertSame('CALLED', $labTicket->fresh()->status, 'The Laboratory ticket must not have moved to IN_SERVICE.');
    }

    /**
     * Same Critical Rule collision, but this time payment was already
     * verified — the 409 body must report that too, not just "unpaid" by
     * default, since the two checks are independent.
     */
    public function test_critical_rule_conflict_reports_verified_payment_status_when_already_paid(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Paid Double Booked Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000012']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        $consService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Active', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $consService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-TEST2', 'priority_score' => 10, 'status' => 'IN_SERVICE']);

        $labService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);
        $labTicket = QueueTicket::create(['service_id' => $labService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'LAB-TEST2', 'priority_score' => 10, 'status' => 'CALLED']);
        $this->verifyPayment($labService);

        $this->actingAs($this->labStaff)
            ->patchJson("/api/queue-tickets/{$labTicket->id}/start-service")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This patient already has an active service in another department.')
            ->assertJsonPath('payment_status', 'verified');

        $this->assertSame('CALLED', $labTicket->fresh()->status, 'The Laboratory ticket must not have moved to IN_SERVICE.');
    }

    /**
     * DoD #3: a visit cannot be marked COMPLETED while any of its services
     * is still Pending, Active, or OnHold.
     */
    public function test_visit_cannot_be_completed_while_a_service_is_still_open(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Open Service Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000003']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'IN_PHARM']);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Completed', 'requires_payment' => true]);
        // Laboratory service left ON_HOLD — visit closure must be rejected.
        Service::create(['visit_id' => $visit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'OnHold', 'requires_payment' => true]);
        $pharmService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->pharm->id, 'service_type' => 'Pharmacy', 'status' => 'Completed', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $pharmService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'PHARM-TEST', 'priority_score' => 10, 'status' => 'COMPLETED']);

        $this->actingAs($this->pharmacyStaff)
            ->patchJson("/api/visits/{$visit->id}/complete")
            ->assertStatus(422);
        $this->assertNotSame('COMPLETED', $visit->fresh()->overall_status);

        // Once the Lab service is resolved (Cancelled here, standing in for
        // "no longer needed"), closure succeeds.
        Service::where('visit_id', $visit->id)->where('department_id', $this->lab->id)->update(['status' => 'Cancelled']);

        $this->actingAs($this->pharmacyStaff)
            ->patchJson("/api/visits/{$visit->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('visit.overall_status', 'COMPLETED');
    }

    public function test_only_clinical_staff_may_request_a_new_service(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Role Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000004']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);

        // Registration Staff may only forward a visit that's currently
        // IN_SERVICE at Registration itself (their one self-check-in
        // handoff) — this visit has no active service of any kind yet, so
        // they're rejected same as any other non-clinical role would be.
        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(403);

        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'requested_tests_other' => 'Full blood count.',
        ])->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(201);
    }
}
