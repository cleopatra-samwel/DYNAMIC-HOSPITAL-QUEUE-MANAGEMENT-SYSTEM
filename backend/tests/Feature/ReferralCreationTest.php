<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Referral;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

/**
 * Doctor Role expansion — "Refer to Another Department" Next Action.
 * Referral is created atomically alongside the new Service/QueueTicket by
 * ServiceFlowController::store, and its status is computed live from that
 * ticket's own status (see Referral::getStatusAttribute()), never stored.
 */
class ReferralCreationTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $bill;

    private User $doctor;

    private User $administrator;

    private Visit $visit;

    private Service $consService;

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
        $this->bill = Department::create(['dept_code' => 'BILL', 'dept_name' => 'Billing', 'is_active' => true]);

        $this->doctor = $this->makeUser('Doctor');
        $this->administrator = $this->makeUser('Administrator');

        $patient = Patient::create([
            'name' => 'Referral Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male',
            'contact' => '0700000002', 'patient_number' => 'P-REF-1',
        ]);

        $visitRes = $this->actingAs($this->doctor)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $this->visit = Visit::find($visitRes->json('visit.id'));
        $this->consService = Service::find($visitRes->json('visit.services.0.id'));
        $consTicketId = $visitRes->json('visit.services.0.queue_ticket.id');

        $this->verifyPayment($this->consService);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/start-service")->assertStatus(200);
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

    public function test_doctor_can_refer_a_patient_to_another_department_and_a_referral_row_is_created_atomically_with_the_new_service(): void
    {
        $res = $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $this->bill->id,
            'referral' => ['reason' => 'Insurance pre-authorization needed', 'notes' => 'Patient carries NHIF.'],
        ])->assertStatus(201);

        $this->assertNotNull($res->json('referral'));
        $this->assertSame('Insurance pre-authorization needed', $res->json('referral.reason'));
        $this->assertSame('Pending', $res->json('referral.status'));

        $this->assertDatabaseHas('referrals', [
            'visit_id' => $this->visit->id,
            'from_service_id' => $this->consService->id,
            'to_department_id' => $this->bill->id,
            'referred_by' => $this->doctor->id,
            'reason' => 'Insurance pre-authorization needed',
        ]);

        $referral = Referral::where('visit_id', $this->visit->id)->firstOrFail();
        $this->assertSame($res->json('service.id'), $referral->to_service_id);
    }

    public function test_referral_cannot_target_registration_or_the_current_department(): void
    {
        $reg = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);

        $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $reg->id,
            'referral' => ['reason' => 'Should be rejected regardless — REG is never a target'],
        ])->assertStatus(422);

        $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $this->cons->id,
            'referral' => ['reason' => 'Cannot refer to the department the patient is already in'],
        ])->assertStatus(422);

        $this->assertSame(0, Referral::count());
    }

    public function test_referral_status_is_computed_from_the_linked_tickets_live_status_not_stored(): void
    {
        $res = $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $this->bill->id,
            'referral' => ['reason' => 'Billing review'],
        ])->assertStatus(201);

        $referral = Referral::where('visit_id', $this->visit->id)->firstOrFail();
        $this->assertSame('Pending', $referral->fresh()->status);

        // BILL tickets are only actionable by Cashier/Billing Staff
        // (DepartmentRoles) — not the referring Doctor.
        $billingStaff = $this->makeUser('Cashier/Billing Staff');

        $billTicketId = $res->json('service.queue_ticket.id');
        $this->actingAs($billingStaff)->patchJson("/api/queue-tickets/{$billTicketId}/call")->assertStatus(200);
        $this->assertSame('In Progress', $referral->fresh()->status);

        $this->actingAs($billingStaff)->patchJson("/api/queue-tickets/{$billTicketId}/cancel")->assertStatus(200);
        $this->assertSame('Cancelled', $referral->fresh()->status);
    }

    public function test_referrals_index_defaults_to_only_the_requesting_doctors_own_referrals(): void
    {
        $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $this->bill->id,
            'referral' => ['reason' => 'Mine'],
        ])->assertStatus(201);

        $otherDoctor = $this->makeUser('Doctor');

        $res = $this->actingAs($otherDoctor)->getJson('/api/referrals')->assertStatus(200);
        $this->assertSame(0, count($res->json('data')));

        $res = $this->actingAs($this->doctor)->getJson('/api/referrals')->assertStatus(200);
        $this->assertSame(1, count($res->json('data')));
    }

    public function test_administrator_sees_all_referrals(): void
    {
        $this->actingAs($this->doctor)->postJson("/api/visits/{$this->visit->id}/services", [
            'department_id' => $this->bill->id,
            'referral' => ['reason' => 'Anyone\'s referral'],
        ])->assertStatus(201);

        $res = $this->actingAs($this->administrator)->getJson('/api/referrals')->assertStatus(200);
        $this->assertSame(1, count($res->json('data')));
    }
}
