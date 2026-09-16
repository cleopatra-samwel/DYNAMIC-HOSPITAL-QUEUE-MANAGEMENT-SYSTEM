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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

class OnHoldResolutionTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $lab;

    private Department $pharm;

    private User $doctor;

    private User $labStaff;

    private User $pharmacyStaff;

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
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true]);

        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');
        $this->pharmacyStaff = $this->makeUser('Pharmacy Staff');
        $this->admin = $this->makeUser('Administrator');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'.hold@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeVisitInService(Department $department, string $status = 'IN_SERVICE'): array
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Hold Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'IN_'.$department->dept_code]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => $department->dept_name, 'status' => 'Active', 'requires_payment' => true]);
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => strtoupper($department->dept_code).'-'.uniqid(), 'priority_score' => 10, 'status' => $status]);

        return [$visit, $service, $ticket];
    }

    /** Mechanism 1: routing back into the SAME department auto-resolves the earlier hold. */
    public function test_auto_resolves_hold_when_patient_returns_to_same_department(): void
    {
        [$visit, $consService, $consTicket] = $this->makeVisitInService($this->cons);

        // Doctor sends to Lab, expecting the patient back personally —
        // requires requested_tests filled first.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'requested_tests_other' => 'Full blood count.',
        ])->assertStatus(200);

        $labRes = $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id, 'hold_current' => true])
            ->assertStatus(201);
        $this->assertSame('OnHold', $consService->fresh()->status);
        $labTicketId = $labRes->json('service.queue_ticket.id');
        $this->verifyPayment(Service::find($labRes->json('service.id')));

        // Lab does the test, then forwards back to Consultation — requires
        // lab_results_notes filled first.
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/call")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/start-service")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'lab_results_notes' => 'All normal.',
        ])->assertStatus(200);

        $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);

        // The original held Consultation service is now auto-resolved.
        $this->assertSame('Completed', $consService->fresh()->status);

        $event = QueueEvent::where('queue_ticket_id', $consTicket->id)->where('event_type', 'HOLD_AUTO_RESOLVED')->first();
        $this->assertNotNull($event, 'Expected a HOLD_AUTO_RESOLVED event for the original ticket.');
        $this->assertStringContainsString('Consultation', $event->notes);
    }

    /** Auto-resolve must NOT fire for a hold in a DIFFERENT department. */
    public function test_does_not_auto_resolve_hold_in_a_different_department(): void
    {
        [$visit, $consService] = $this->makeVisitInService($this->cons);

        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'requested_tests_other' => 'Full blood count.',
        ])->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id, 'hold_current' => true])
            ->assertStatus(201);
        $this->assertSame('OnHold', $consService->fresh()->status);

        // Routing onward to Pharmacy (a different department) must not
        // touch the CONS hold at all — the doctor's final review fields
        // must already be filled for this direct-to-Pharmacy path to be
        // allowed at all (Part 3.3's gate applies here too).
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'final_diagnosis' => 'Resolved.',
            'treatment_plan' => 'Medication course.',
            'patient_signature_name' => 'Hold Test Patient',
            'patient_signature_phone' => '0700000000',
        ])->assertStatus(200);

        [, , $labTicket] = [null, null, QueueTicket::where('status', 'WAITING')->first()];
        $this->verifyPayment($labTicket->service);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicket->id}/call")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicket->id}/start-service")->assertStatus(200);
        $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(201);

        $this->assertSame('OnHold', $consService->fresh()->status, 'A hold in a different department must never auto-resolve.');
    }

    /** Mechanism 2: explicit staff resolution for the "never came back" case. */
    public function test_doctor_can_explicitly_resolve_a_stale_hold_with_a_reason(): void
    {
        [, $consService, $consTicket] = $this->makeVisitInService($this->cons);
        $consService->update(['status' => 'OnHold']);

        $this->actingAs($this->doctor)
            ->patchJson("/api/services/{$consService->id}/resolve-hold", ['reason' => 'Patient discharged elsewhere, will not return.'])
            ->assertStatus(200)
            ->assertJsonPath('service.status', 'Cancelled');

        $event = QueueEvent::where('queue_ticket_id', $consTicket->id)->where('event_type', 'HOLD_RESOLVED')->first();
        $this->assertNotNull($event);
        $this->assertSame('Patient discharged elsewhere, will not return.', $event->notes);
        $this->assertSame($this->doctor->id, $event->performed_by);
    }

    public function test_resolve_hold_requires_a_reason(): void
    {
        [, $consService] = $this->makeVisitInService($this->cons);
        $consService->update(['status' => 'OnHold']);

        $this->actingAs($this->doctor)
            ->patchJson("/api/services/{$consService->id}/resolve-hold", [])
            ->assertStatus(422);
    }

    public function test_only_doctor_or_administrator_may_resolve_a_hold(): void
    {
        [, $consService] = $this->makeVisitInService($this->cons);
        $consService->update(['status' => 'OnHold']);

        $this->actingAs($this->labStaff)
            ->patchJson("/api/services/{$consService->id}/resolve-hold", ['reason' => 'Not my call.'])
            ->assertStatus(403);

        $this->assertSame('OnHold', $consService->fresh()->status);
    }

    public function test_cannot_resolve_a_service_that_is_not_on_hold(): void
    {
        [, $consService] = $this->makeVisitInService($this->cons);
        // Left as 'Active' — never put OnHold.

        $this->actingAs($this->doctor)
            ->patchJson("/api/services/{$consService->id}/resolve-hold", ['reason' => 'Should not work.'])
            ->assertStatus(422);
    }

    /**
     * The explicit requirement: nothing else in the system — not even a
     * Pharmacy visit-closure attempt — may silently resolve a stale hold.
     * The visit stays blocked until a human uses mechanism 1 or 2.
     */
    public function test_visit_closure_never_silently_resolves_a_stale_hold(): void
    {
        [$visit, $consService] = $this->makeVisitInService($this->cons);
        $consService->update(['status' => 'OnHold']);

        // Every OTHER service for the visit is done.
        $pharmService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->pharm->id, 'service_type' => 'Pharmacy', 'status' => 'Completed', 'requires_payment' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        QueueTicket::create(['service_id' => $pharmService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'PHARM-CLOSE', 'priority_score' => 10, 'status' => 'COMPLETED']);

        $this->actingAs($this->pharmacyStaff)
            ->patchJson("/api/visits/{$visit->id}/complete")
            ->assertStatus(422);

        $this->assertSame('OnHold', $consService->fresh()->status, 'Closure must not touch the hold.');
        $this->assertNotSame('COMPLETED', $visit->fresh()->overall_status);
    }

    /** Mechanism 3: visibility of forgotten holds, Administrator-only. */
    public function test_open_holds_lists_stale_holds_and_is_administrator_only(): void
    {
        // Model::update() auto-touches updated_at back to now(), so a custom
        // timestamp has to bypass that via a plain query-builder update.
        [, $staleService] = $this->makeVisitInService($this->cons);
        Service::whereKey($staleService->id)->update(['status' => 'OnHold', 'updated_at' => now()->subHours(5)]);

        [, $recentService] = $this->makeVisitInService($this->lab);
        Service::whereKey($recentService->id)->update(['status' => 'OnHold', 'updated_at' => now()->subMinutes(10)]);

        $this->actingAs($this->doctor)->getJson('/api/services/open-holds')->assertStatus(403);

        $response = $this->actingAs($this->admin)->getJson('/api/services/open-holds?hours=2')->assertStatus(200);
        $ids = collect($response->json('holds'))->pluck('service_id');

        $this->assertTrue($ids->contains($staleService->id), 'The 5-hour-old hold should appear.');
        $this->assertFalse($ids->contains($recentService->id), 'The 10-minute-old hold should not appear yet.');
    }
}
