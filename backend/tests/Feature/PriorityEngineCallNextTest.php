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
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriorityEngineCallNextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'High', 'weight' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Medium', 'weight' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function makeUserWithRole(string $role): User
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

    private function makeWaitingTicket(Department $department, PriorityLevel $level, int $minutesAgo, ?int $doctorId = null): QueueTicket
    {
        $patient = Patient::create([
            'patient_number' => 'P-'.uniqid(),
            'name' => 'Load Test Patient',
            'date_of_birth' => '1990-01-01',
            'gender' => 'Male',
            'contact' => '0000000000',
        ]);

        $visit = Visit::create([
            'patient_id' => $patient->id,
            'visit_date' => now()->toDateString(),
            'patient_type' => 'Normal',
            'emergency_confirmed' => false,
            'payment_method' => 'Cash',
            'overall_status' => 'WAITING_'.$department->dept_code,
        ]);

        $service = Service::create([
            'visit_id' => $visit->id,
            'department_id' => $department->id,
            'doctor_id' => $doctorId,
            'service_type' => $department->dept_name,
            'status' => 'Pending',
            'requires_payment' => true,
        ]);

        $ticket = QueueTicket::create([
            'service_id' => $service->id,
            'priority_level_id' => $level->id,
            'queue_number' => 'T-'.uniqid(),
            'priority_score' => $level->weight,
            'status' => 'WAITING',
        ]);

        $ticket->created_at = now()->subMinutes($minutesAgo);
        $ticket->save();

        return $ticket;
    }

    /**
     * DoD #1: 120 simulated WAITING tickets (mixed priority levels, distinct
     * waiting times so scores don't coincidentally tie) — repeatedly calling
     * callNext() must always return the correct highest-score ticket next,
     * never the same ticket twice, and never skip one. We compute the
     * expected order independently (sort by score desc, then created_at
     * asc) and assert the engine's actual call order matches it exactly.
     */
    public function test_call_next_always_selects_highest_score_with_no_duplicates_or_skips(): void
    {
        $this->travelTo(Carbon::now()); // freeze time so scores can't drift mid-test

        config(['queue_priority.aging_points_per_minute' => 2]);

        $department = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $levels = PriorityLevel::orderByDesc('weight')->get();
        $engine = new PriorityEngine();
        $caller = $this->makeUserWithRole('Laboratory Staff');

        $ticketCount = 120;
        $expected = [];

        for ($i = 0; $i < $ticketCount; $i++) {
            $level = $levels[$i % $levels->count()];
            $minutesAgo = $i; // distinct waiting time per ticket, avoids score ties
            $ticket = $this->makeWaitingTicket($department, $level, $minutesAgo);

            $expected[] = [
                'id' => $ticket->id,
                'score' => $level->weight + ($minutesAgo * 2),
                'created_at' => $ticket->created_at,
            ];
        }

        usort($expected, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score']; // highest score first
            }

            return $a['created_at'] <=> $b['created_at']; // earliest created_at wins ties
        });
        $expectedOrder = array_column($expected, 'id');

        $actualOrder = [];
        for ($i = 0; $i < $ticketCount; $i++) {
            $called = $engine->callNext($department->id, $caller->id);
            $this->assertNotNull($called, "callNext returned null too early, at call #{$i}");
            $actualOrder[] = $called->id;
        }

        $this->assertSame($expectedOrder, $actualOrder);
        $this->assertCount($ticketCount, array_unique($actualOrder), 'A ticket was selected more than once.');

        // Nothing left waiting — the next call must return null, not error or repeat.
        $this->assertNull($engine->callNext($department->id, $caller->id));
    }

    public function test_every_call_next_logs_exactly_one_queue_event(): void
    {
        $department = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $level = PriorityLevel::where('name', 'Normal')->first();
        $engine = new PriorityEngine();
        $caller = $this->makeUserWithRole('Laboratory Staff');

        $this->makeWaitingTicket($department, $level, 0);
        $this->makeWaitingTicket($department, $level, 1);

        $this->assertSame(0, QueueEvent::count());

        $engine->callNext($department->id, $caller->id);
        $this->assertSame(1, QueueEvent::count());

        $engine->callNext($department->id, $caller->id);
        $this->assertSame(2, QueueEvent::count());

        $this->assertSame(2, QueueEvent::where('event_type', 'CALLED')->count());
    }

    public function test_call_next_endpoint_is_restricted_to_the_departments_allowed_role(): void
    {
        $department = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $level = PriorityLevel::where('name', 'Normal')->first();
        $this->makeWaitingTicket($department, $level, 0);

        $wrongRoleUser = $this->makeUserWithRole('Registration Staff');
        $this->actingAs($wrongRoleUser)
            ->postJson("/api/departments/{$department->id}/call-next")
            ->assertStatus(403);

        $rightRoleUser = $this->makeUserWithRole('Laboratory Staff');
        $this->actingAs($rightRoleUser)
            ->postJson("/api/departments/{$department->id}/call-next")
            ->assertStatus(200)
            ->assertJsonPath('ticket.status', 'CALLED');
    }

    /**
     * DoD #1/#2 — optional doctor-scoped tickets (registration's new
     * "Preferred Doctor" field). Exact scoping added to
     * PriorityEngine::callNext(): a ticket's service.doctor_id must be
     * either NULL or equal to whichever user's id is calling — no role
     * check needed, since a non-matching doctor_id simply excludes the
     * ticket from anyone else's selection, doctor or not.
     */
    public function test_a_doctor_specific_ticket_is_skipped_by_a_different_doctors_call_next(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $level = PriorityLevel::where('name', 'Normal')->first();
        $engine = new PriorityEngine();

        $assignedDoctor = $this->makeUserWithRole('Doctor');
        $otherDoctor = $this->makeUserWithRole('Doctor');

        $reservedTicket = $this->makeWaitingTicket($department, $level, 10, doctorId: $assignedDoctor->id);

        // The other doctor's callNext() must skip the reserved ticket
        // entirely — with nothing else waiting, it returns null rather
        // than calling a ticket that isn't theirs.
        $this->assertNull($engine->callNext($department->id, $otherDoctor->id));
        $this->assertSame('WAITING', $reservedTicket->fresh()->status, 'A doctor-specific ticket must not be callable by a different doctor.');

        // The assigned doctor's own call succeeds normally.
        $called = $engine->callNext($department->id, $assignedDoctor->id);
        $this->assertNotNull($called);
        $this->assertSame($reservedTicket->id, $called->id);
        $this->assertSame('CALLED', $called->status);
    }

    /** A doctor-specific ticket must not block a DIFFERENT doctor from calling other, unassigned patients waiting in the same department. */
    public function test_a_doctor_specific_ticket_does_not_block_other_waiting_tickets_from_a_different_doctor(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $level = PriorityLevel::where('name', 'Normal')->first();
        $engine = new PriorityEngine();

        $assignedDoctor = $this->makeUserWithRole('Doctor');
        $otherDoctor = $this->makeUserWithRole('Doctor');

        // Reserved ticket waited longer (higher aging) so it would
        // otherwise be selected FIRST on priority alone — proving the
        // doctor scope, not just priority order, is what skips it.
        $this->makeWaitingTicket($department, $level, 20, doctorId: $assignedDoctor->id);
        $noPreferenceTicket = $this->makeWaitingTicket($department, $level, 1, doctorId: null);

        $called = $engine->callNext($department->id, $otherDoctor->id);

        $this->assertNotNull($called);
        $this->assertSame($noPreferenceTicket->id, $called->id, 'A ticket with no doctor preference must remain callable by any doctor.');
    }

    /** DoD #2 — no doctor preference (doctor_id null, the overwhelming majority) behaves exactly as before this feature: callable by any doctor. */
    public function test_a_ticket_with_no_doctor_preference_is_callable_by_any_doctor(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $level = PriorityLevel::where('name', 'Normal')->first();
        $engine = new PriorityEngine();

        $anyDoctor = $this->makeUserWithRole('Doctor');
        $ticket = $this->makeWaitingTicket($department, $level, 5, doctorId: null);

        $called = $engine->callNext($department->id, $anyDoctor->id);

        $this->assertNotNull($called);
        $this->assertSame($ticket->id, $called->id);
    }
}
