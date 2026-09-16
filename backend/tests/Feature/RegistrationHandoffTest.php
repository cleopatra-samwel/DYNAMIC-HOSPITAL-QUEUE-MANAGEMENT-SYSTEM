<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

/**
 * The end-to-end replacement for the old "Pending Check-Ins" flow: a self
 * check-in lands directly in Registration's own queue with a real ticket
 * (see CheckInTest), Registration Staff call/start-service it exactly like
 * any other department, confirm/correct the placeholder patient_type and
 * payment_method (VisitController::updateRegistrationDetails), then
 * forward to the patient's actual target department
 * (ServiceFlowController::store) — which is also what completes the
 * Registration ticket and sends the TRANSFERRED notification/SMS.
 */
class RegistrationHandoffTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $reg;

    private Department $cons;

    private Department $lab;

    private User $registrationStaff;

    private User $doctor;

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

        $this->reg = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);
        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role, 'last_name' => 'Tester', 'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'-'.uniqid().'@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: Visit, 1: QueueTicket} the visit and its REG ticket, already CALLED + IN_SERVICE. */
    private function checkInAndStartService(string $contact = '0700000010'): array
    {
        $response = $this->postJson('/api/check-ins', [
            'name' => 'Handoff Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => $contact,
        ])->assertStatus(201);

        $ticket = QueueTicket::where('queue_number', $response->json('queue_number'))->firstOrFail();

        $this->actingAs($this->registrationStaff)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertStatus(200);
        $this->actingAs($this->registrationStaff)->patchJson("/api/queue-tickets/{$ticket->id}/start-service")->assertStatus(200);

        return [$ticket->service->visit, $ticket->fresh()];
    }

    public function test_registration_staff_can_confirm_registration_details_while_the_visit_is_at_registration(): void
    {
        [$visit] = $this->checkInAndStartService();

        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/registration-details", [
                'patient_type' => 'Emergency',
                'payment_method' => 'Insurance',
                'insurance_provider' => 'NHIF',
                'insurance_ref' => 'REF-001',
            ])
            ->assertStatus(200)
            ->assertJsonPath('visit.patient_type', 'Emergency')
            ->assertJsonPath('visit.payment_method', 'Insurance');
    }

    public function test_non_registration_staff_cannot_update_registration_details(): void
    {
        [$visit] = $this->checkInAndStartService();

        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$visit->id}/registration-details", [
                'patient_type' => 'Normal', 'payment_method' => 'Cash',
            ])
            ->assertStatus(403);
    }

    public function test_registration_details_cannot_be_updated_once_the_visit_has_moved_past_registration(): void
    {
        [$visit] = $this->checkInAndStartService();

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);

        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/registration-details", [
                'patient_type' => 'Normal', 'payment_method' => 'Cash',
            ])
            ->assertStatus(422);
    }

    /**
     * The full handoff: Registration Staff forward a self check-in's REG
     * ticket to the patient's real target department — this both completes
     * the REG ticket and opens the first real clinical service, using the
     * patient_type set moments earlier to decide the new ticket's priority.
     */
    public function test_registration_staff_can_forward_from_registration_to_the_target_department(): void
    {
        [$visit, $regTicket] = $this->checkInAndStartService();

        $this->actingAs($this->registrationStaff)->patchJson("/api/visits/{$visit->id}/registration-details", [
            'patient_type' => 'Emergency', 'payment_method' => 'Cash',
        ])->assertStatus(200);

        $response = $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);

        $this->assertSame('COMPLETED', $regTicket->fresh()->status);
        $this->assertSame('WAITING_CONS', $visit->fresh()->overall_status);
        $this->assertSame('Critical', $response->json('service.queue_ticket.priority_level.name'));

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visit->id,
            'queue_ticket_id' => $response->json('service.queue_ticket.id'),
            'type' => 'TRANSFERRED',
        ]);
    }

    /** Registration Staff's handoff permission is scoped to their OWN department's ticket — never a clinical one. */
    public function test_registration_staff_cannot_forward_a_visit_that_is_active_in_a_clinical_department(): void
    {
        [$visit] = $this->checkInAndStartService();

        $this->actingAs($this->registrationStaff)->patchJson("/api/visits/{$visit->id}/registration-details", [
            'patient_type' => 'Normal', 'payment_method' => 'Cash',
        ])->assertStatus(200);

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);

        // Get the new Consultation ticket to IN_SERVICE (payment verified
        // first — Consultation is billable), then confirm Registration
        // Staff still can't touch this stage.
        $consService = $visit->services()->latest('id')->first();
        $this->verifyPayment($consService);
        $consTicketId = QueueTicket::where('service_id', $consService->id)->value('id');
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/start-service")->assertStatus(200);

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(403);
    }

    public function test_cannot_forward_any_visit_back_into_registration(): void
    {
        [$visit] = $this->checkInAndStartService();

        $this->actingAs($this->registrationStaff)->patchJson("/api/visits/{$visit->id}/registration-details", [
            'patient_type' => 'Normal', 'payment_method' => 'Cash',
        ])->assertStatus(200);

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->reg->id])
            ->assertStatus(422);
    }
}
