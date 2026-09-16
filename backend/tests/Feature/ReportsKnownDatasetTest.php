<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9, DoD #1 — a small, known dataset (5 visits: 3 Normal Completed,
 * 1 Emergency Completed+confirmed, 1 Emergency still pending confirmation)
 * with hand-picked wait times, asserted against hand-calculated expected
 * values for every report — not just "returns 200".
 *
 * Dataset (all on TODAY, department CONS, weights: Normal=10, Critical=100):
 *   A — Normal,    ticket COMPLETED, created 08:00, called 08:10 (10 min), priority_score 10
 *   B — Normal,    ticket COMPLETED, created 08:00, called 08:20 (20 min), priority_score 10
 *   C — Normal,    ticket COMPLETED, created 08:00, called 08:30 (30 min), priority_score 10
 *   D — Emergency (confirmed),  ticket COMPLETED, created 08:00, called 08:05 (5 min), priority_score 100
 *   E — Emergency (unconfirmed), ticket WAITING, created 08:00, never called
 */
class ReportsKnownDatasetTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private User $administrator;

    private string $today;

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
        $this->administrator = User::create([
            'first_name' => 'Admin', 'last_name' => 'Tester', 'name' => 'Admin Tester',
            'email' => 'admin-'.uniqid().'@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $this->administrator->assignRole('Administrator');

        $this->today = Carbon::today()->toDateString();

        $this->seedKnownDataset();
    }

    private function seedKnownDataset(): void
    {
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $critical = PriorityLevel::where('name', 'Critical')->first();
        $created = Carbon::today()->setTime(8, 0, 0);

        $make = function (string $patientType, bool $emergencyConfirmed, string $ticketStatus, ?Carbon $calledAt, int $priorityScore, $priorityLevel, string $overallStatus) use ($created) {
            $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Dataset Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '07'.rand(10000000, 99999999)]);
            $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => $this->today, 'patient_type' => $patientType, 'emergency_confirmed' => $emergencyConfirmed, 'payment_method' => 'Cash', 'overall_status' => $overallStatus]);
            $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => $ticketStatus === 'COMPLETED' ? 'Completed' : 'Pending', 'requires_payment' => false]);
            $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $priorityLevel->id, 'queue_number' => 'CONS-'.uniqid(), 'priority_score' => $priorityScore, 'status' => $ticketStatus]);
            // created_at/called_at need explicit control — set via query builder
            // form (see this codebase's established Eloquent gotcha: the model
            // ->update() form silently re-touches updated_at over a custom
            // value, but more importantly here we need created_at itself set
            // precisely, which factory/create() already stamped to "now").
            QueueTicket::whereKey($ticket->id)->update(['created_at' => $created, 'called_at' => $calledAt]);

            return $ticket->fresh();
        };

        $make('Normal', false, 'COMPLETED', $created->copy()->addMinutes(10), 10, $normal, 'COMPLETED');
        $make('Normal', false, 'COMPLETED', $created->copy()->addMinutes(20), 10, $normal, 'COMPLETED');
        $make('Normal', false, 'COMPLETED', $created->copy()->addMinutes(30), 10, $normal, 'COMPLETED');
        $make('Emergency', true, 'COMPLETED', $created->copy()->addMinutes(5), 100, $critical, 'COMPLETED');
        $make('Emergency', false, 'WAITING', null, 100, $critical, 'WAITING_CONS');
    }

    public function test_daily_patients_report_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/daily-patients?date={$this->today}")
            ->assertStatus(200);

        $response->assertJson([
            'date' => $this->today,
            'total_visits' => 5,
            'normal_count' => 3,
            'emergency_count' => 2,
            'completed_count' => 4,
        ]);
    }

    /**
     * Pins the currently-correct handling of the overall_status casing
     * inconsistency confirmed live in this codebase: the visits migration
     * defaults overall_status to the literal string 'Registered' (mixed
     * case), while every real write path (QueueJourney::stateFor, plus the
     * explicit 'COMPLETED' set in VisitController::complete) only ever
     * produces SCREAMING_SNAKE_CASE. A visit left sitting at that raw
     * default is not completed and must never count as one — this is
     * already correct by construction (completed_count filters for the
     * exact string 'COMPLETED', which 'Registered' can never match), but
     * was not explicitly pinned by a test. If the migration default is
     * ever "cleaned up" without knowing this history, this test catches
     * a regression immediately.
     */
    public function test_visit_at_the_raw_registered_default_is_excluded_from_completed_count(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Unregistered Default Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000220']);

        // Deliberately NOT setting overall_status — this is the one case
        // in the whole codebase where that's correct: it lets the DB
        // column's own default ('Registered') apply, exactly reproducing
        // the real historical row this test is guarding against.
        Visit::create(['patient_id' => $patient->id, 'visit_date' => $this->today, 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash']);

        $this->assertDatabaseHas('visits', ['patient_id' => $patient->id, 'overall_status' => 'Registered']);

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/daily-patients?date={$this->today}")
            ->assertStatus(200);

        // total_visits/normal_count grow to include this new visit (it IS
        // a real Normal visit on this date) — only completed_count must
        // stay exactly as it was, since this visit is not completed.
        $response->assertJson([
            'total_visits' => 6,
            'normal_count' => 4,
            'emergency_count' => 2,
            'completed_count' => 4,
        ]);
    }

    public function test_waiting_time_report_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/waiting-time?date={$this->today}")
            ->assertStatus(200);

        $departments = collect($response->json('departments'));
        $this->assertCount(1, $departments, 'Only CONS has tickets in this dataset.');

        $cons = $departments->first();
        $this->assertSame($this->cons->id, $cons['department_id']);
        // (10 + 20 + 30 + 5) / 4 = 16.25 -> 16.3; ticket E excluded (never called).
        $this->assertSame(16.3, $cons['average_minutes']);
        $this->assertSame(30, $cons['max_minutes']);
        $this->assertSame(4, $cons['sample_size']);
    }

    public function test_department_report_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/department?date={$this->today}&department_id={$this->cons->id}")
            ->assertStatus(200);

        $response->assertJson([
            'tickets_served' => 4,
            'tickets_waiting' => 1,
            // (10 + 10 + 10 + 100) / 4 = 32.5
            'average_priority_score_at_call' => 32.5,
            'no_show_count' => 0,
        ]);
    }

    public function test_queue_performance_report_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/queue-performance?date={$this->today}")
            ->assertStatus(200);

        $response->assertJson([
            'total_served' => 4,
            'total_waiting' => 1,
            'total_no_shows' => 0,
            'total_cancelled' => 0,
        ]);
    }

    public function test_emergency_report_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/emergency?date={$this->today}")
            ->assertStatus(200);

        $response->assertJson([
            'emergency_count' => 2,
            'confirmed_count' => 1,
            'pending_confirmation_count' => 1,
            // Only the confirmed emergency ticket (D) was ever called: 5 min.
            'average_minutes_to_called' => 5.0,
        ]);
    }

    /** system-summary is live ("now"), not date-scoped like the 5 reports above — separate, tolerant assertion for the one genuinely time-dependent figure. */
    public function test_system_summary_matches_hand_calculated_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/reports/system-summary')
            ->assertStatus(200);

        $response->assertJson([
            'total_patients_today' => 5,
            'currently_waiting' => 1,
            'total_served_today' => 4,
        ]);

        $longest = $response->json('longest_currently_waiting');
        $this->assertNotNull($longest);
        $expectedMinutes = now()->diffInMinutes(Carbon::today()->setTime(8, 0, 0));
        $this->assertEqualsWithDelta($expectedMinutes, $longest['waiting_minutes'], 1, 'Live "waiting so far" figure, allowing 1 minute of test-execution drift.');
    }

    /** Queue-performance's no-show/cancelled counting, isolated from the core hand-calculated dataset above so those numbers stay clean. */
    public function test_queue_performance_counts_no_shows_and_cancellations_separately(): void
    {
        $normal = PriorityLevel::where('name', 'Normal')->first();
        foreach (['NO_SHOW', 'CANCELLED'] as $status) {
            $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Extra Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '07'.rand(10000000, 99999999)]);
            $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => $this->today, 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'EXITED']);
            $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Cancelled', 'requires_payment' => false]);
            $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-'.uniqid(), 'priority_score' => 10, 'status' => $status]);
            QueueTicket::whereKey($ticket->id)->update(['created_at' => Carbon::today()->setTime(9, 0, 0)]);
        }

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/reports/queue-performance?date={$this->today}")
            ->assertStatus(200);

        $response->assertJson([
            'total_served' => 4,
            'total_waiting' => 1,
            'total_no_shows' => 1,
            'total_cancelled' => 1,
        ]);
    }
}
