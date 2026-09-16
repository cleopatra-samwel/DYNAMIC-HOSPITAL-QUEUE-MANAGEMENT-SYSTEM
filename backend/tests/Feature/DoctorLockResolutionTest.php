<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\PriorityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The visibility+resolution half of the doctor-selection known limitation
 * (see PriorityEngine::callNext()'s docblock): a ticket reserved for a
 * doctor who never calls it just sits WAITING forever, ineligible to
 * every other doctor. Mirrors OnHoldResolutionTest's exact pattern —
 * openDoctorLocks() is openHolds() for this instead of OnHold services,
 * clearDoctor() is resolveHold() for this instead of an OnHold status.
 */
class DoctorLockResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private User $assignedDoctor;

    private User $otherDoctor;

    private User $admin;

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
        $this->assignedDoctor = $this->makeUser('Doctor');
        $this->otherDoctor = $this->makeUser('Doctor');
        $this->admin = $this->makeUser('Administrator');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'-'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: Service, 1: QueueTicket} */
    private function makeDoctorLockedWaitingTicket(?int $doctorId): array
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Doctor Lock Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'doctor_id' => $doctorId, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-'.uniqid(), 'priority_score' => 10, 'status' => 'WAITING']);

        return [$service, $ticket];
    }

    public function test_administrator_can_clear_a_doctor_assignment_with_a_reason(): void
    {
        [$service, $ticket] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);

        $this->actingAs($this->admin)
            ->patchJson("/api/services/{$service->id}/clear-doctor", ['reason' => 'Dr. X is on leave this week.'])
            ->assertStatus(200)
            ->assertJsonPath('service.doctor_id', null);

        $this->assertNull($service->fresh()->doctor_id);

        $event = QueueEvent::where('queue_ticket_id', $ticket->id)->where('event_type', 'DOCTOR_UNASSIGNED')->first();
        $this->assertNotNull($event);
        $this->assertSame('Dr. X is on leave this week.', $event->notes);
        $this->assertSame($this->admin->id, $event->performed_by);
    }

    public function test_clear_doctor_requires_a_reason(): void
    {
        [$service] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);

        $this->actingAs($this->admin)
            ->patchJson("/api/services/{$service->id}/clear-doctor", [])
            ->assertStatus(422);

        $this->assertSame($this->assignedDoctor->id, $service->fresh()->doctor_id);
    }

    /** Administrator-only — not even the assigned doctor themselves may clear it, matching the spec's explicit "Administrator-only endpoint". */
    public function test_only_administrator_may_clear_a_doctor_assignment(): void
    {
        [$service] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);

        $this->actingAs($this->assignedDoctor)
            ->patchJson("/api/services/{$service->id}/clear-doctor", ['reason' => 'Not my call.'])
            ->assertStatus(403);

        $this->actingAs($this->otherDoctor)
            ->patchJson("/api/services/{$service->id}/clear-doctor", ['reason' => 'Not my call.'])
            ->assertStatus(403);

        $this->assertSame($this->assignedDoctor->id, $service->fresh()->doctor_id);
    }

    public function test_cannot_clear_doctor_on_a_service_with_no_doctor_assigned(): void
    {
        [$service] = $this->makeDoctorLockedWaitingTicket(null);

        $this->actingAs($this->admin)
            ->patchJson("/api/services/{$service->id}/clear-doctor", ['reason' => 'Should not work.'])
            ->assertStatus(422);
    }

    /** Visibility: only WAITING doctor-locked tickets older than the threshold appear, Administrator-only. */
    public function test_open_doctor_locks_lists_stale_locks_and_is_administrator_only(): void
    {
        [$staleService, $staleTicket] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);
        QueueTicket::whereKey($staleTicket->id)->update(['created_at' => now()->subHours(5)]);

        [$recentService, $recentTicket] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);
        QueueTicket::whereKey($recentTicket->id)->update(['created_at' => now()->subMinutes(10)]);

        // No doctor preference at all — must never appear regardless of age.
        [, $noPrefTicket] = $this->makeDoctorLockedWaitingTicket(null);
        QueueTicket::whereKey($noPrefTicket->id)->update(['created_at' => now()->subHours(6)]);

        $this->actingAs($this->assignedDoctor)->getJson('/api/services/doctor-locked')->assertStatus(403);

        $response = $this->actingAs($this->admin)->getJson('/api/services/doctor-locked?hours=2')->assertStatus(200);
        $ids = collect($response->json('locks'))->pluck('service_id');

        $this->assertTrue($ids->contains($staleService->id), 'The 5-hour-old doctor-locked ticket should appear.');
        $this->assertFalse($ids->contains($recentService->id), 'The 10-minute-old lock should not appear yet.');
        $this->assertSame($this->assignedDoctor->fullName(), collect($response->json('locks'))->firstWhere('service_id', $staleService->id)['doctor_name']);
    }

    /** Once called, a doctor-locked ticket is no longer "stuck" and drops off the listing even if old. */
    public function test_open_doctor_locks_excludes_tickets_that_have_already_been_called(): void
    {
        [$service, $ticket] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);
        QueueTicket::whereKey($ticket->id)->update(['created_at' => now()->subHours(5), 'status' => 'CALLED']);

        $response = $this->actingAs($this->admin)->getJson('/api/services/doctor-locked?hours=2')->assertStatus(200);

        $this->assertFalse(collect($response->json('locks'))->pluck('service_id')->contains($service->id));
    }

    /** End-to-end proof this actually fixes the known limitation: clearing doctor_id makes the ticket callable by a different doctor immediately afterward. */
    public function test_clearing_the_doctor_makes_the_ticket_callable_by_any_doctor_again(): void
    {
        [$service, $ticket] = $this->makeDoctorLockedWaitingTicket($this->assignedDoctor->id);
        $engine = new PriorityEngine();

        $this->assertNull($engine->callNext($this->cons->id, $this->otherDoctor->id), 'Still locked — must not be callable yet.');

        $this->actingAs($this->admin)
            ->patchJson("/api/services/{$service->id}/clear-doctor", ['reason' => 'Reassigning — original doctor unavailable.'])
            ->assertStatus(200);

        $called = $engine->callNext($this->cons->id, $this->otherDoctor->id);
        $this->assertNotNull($called);
        $this->assertSame($ticket->id, $called->id);
    }
}
