<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** No auth header at all — a patient has no account. */
    public function test_tracking_page_works_without_authentication_and_reveals_no_patient_identity(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Private Patient Name', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700009999']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0007', 'priority_score' => 10, 'status' => 'WAITING']);

        $response = $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(200);

        $response->assertJsonPath('queue_number', 'CONS-0007')
            ->assertJsonPath('status', 'WAITING')
            ->assertJsonPath('overall_status', 'WAITING_CONS')
            ->assertJsonPath('department', 'Consultation');

        $body = $response->json();
        $this->assertStringNotContainsString('Private Patient Name', json_encode($body));
        $this->assertStringNotContainsString('0700009999', json_encode($body));
        $this->assertArrayNotHasKey('patient', $body);
        $this->assertArrayNotHasKey('patient_name', $body);
        $this->assertArrayNotHasKey('contact', $body);
    }

    public function test_tracking_page_reflects_current_status_not_stale_data_after_a_status_change(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Reopen Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000097']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0008', 'priority_score' => 10, 'status' => 'WAITING']);

        $this->getJson("/api/track/{$visit->tracking_token}")->assertJsonPath('status', 'WAITING');

        // Simulate the change happening while the patient's tracking page
        // is closed / disconnected — a fresh GET (the reconnect refetch)
        // must show the new state, not what it saw before closing.
        $ticket->update(['status' => 'CALLED', 'called_at' => now()]);

        $this->getJson("/api/track/{$visit->tracking_token}")->assertJsonPath('status', 'CALLED');
    }

    public function test_unknown_tracking_token_returns_404(): void
    {
        $this->getJson('/api/track/'.\Illuminate\Support\Str::uuid())->assertStatus(404);
    }

    /**
     * This endpoint carries no authentication at all — unlike every other
     * route, nothing costs an attacker valid credentials first — so it's
     * throttled (throttle:30,1) independently of that. 30/minute is well
     * above a phone auto-refreshing or reconnecting after a signal drop.
     */
    public function test_tracking_endpoint_is_rate_limited_to_30_requests_per_minute(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Rate Limit Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000095']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(200);
        }

        $response = $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(429);
        $this->assertThrottleResponseIsClean($response);
    }

    /**
     * The 429 body must never leak file paths, exception class, or a stack
     * trace — this app's phpunit.xml doesn't override APP_DEBUG, so this
     * test runs with the same APP_DEBUG=true as local .env, proving the
     * custom render() in bootstrap/app.php holds even in debug mode, not
     * just because testing happens to be non-debug.
     */
    private function assertThrottleResponseIsClean($response): void
    {
        $this->assertTrue(config('app.debug'), 'This assertion is only meaningful if APP_DEBUG is true during the test.');
        $retryAfter = $response->json('retry_after');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan(0, $retryAfter);
        // assertExactJson checks the full body has no OTHER keys — this is
        // what actually catches 'exception'/'file'/'trace' leaking back in.
        $response->assertExactJson(['message' => 'Too Many Attempts.', 'retry_after' => $retryAfter]);
    }

    /**
     * DoD #3 — a known, seeded scenario: two tickets genuinely ahead (one
     * higher priority, one same-priority-but-earlier) and one genuinely
     * behind, confirming queue_numbers_ahead lists exactly the right two
     * ticket numbers, in the same priority order PriorityEngine::callNext()
     * would call them, and nothing else.
     */
    public function test_queue_numbers_ahead_lists_exactly_the_correct_tickets_in_priority_order(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $critical = PriorityLevel::where('name', 'Critical')->first();

        $makeTicket = function (string $queueNumber, $level, int $minutesAgo) use ($department) {
            $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Queue Ahead Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700'.rand(100000, 999999)]);
            $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
            $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
            $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $level->id, 'queue_number' => $queueNumber, 'priority_score' => $level->weight, 'status' => 'WAITING']);
            $ticket->created_at = now()->subMinutes($minutesAgo);
            $ticket->save();

            return [$visit, $ticket];
        };

        // Higher priority score — ahead regardless of arrival time.
        $makeTicket('CONS-0021', $critical, 1);
        // Same priority score as the tracked ticket, but arrived earlier — ahead on the tie-break.
        $makeTicket('CONS-0022', $normal, 20);
        [$myVisit, $myTicket] = $makeTicket('CONS-0023', $normal, 10);
        // Same priority score, arrived LATER — behind, must not appear.
        $makeTicket('CONS-0024', $normal, 1);

        $response = $this->getJson("/api/track/{$myVisit->tracking_token}")->assertStatus(200);

        $response->assertJsonPath('queue_number', 'CONS-0023')
            ->assertJsonPath('position', 2)
            ->assertJsonPath('queue_numbers_ahead', ['CONS-0021', 'CONS-0022']);

        $this->assertIsInt($response->json('estimated_wait_minutes'));
        $this->assertGreaterThan(0, $response->json('estimated_wait_minutes'));
    }

    /**
     * Exercises the REAL historical-average branch (not the empty-history
     * fallback) with a known, exact answer — this is what actually caught
     * a real bug during manual live verification: computing the diff in
     * the wrong direction produced a negative average (and therefore a
     * silently-wrong-but-not-erroring 0-minute ETA) until
     * absolute: true was added, matching ReportController's own
     * established pattern for the exact same kind of calculation.
     */
    public function test_estimated_wait_uses_the_real_average_of_recent_completed_tickets(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        // Three completed tickets, exactly 20/30/40 minutes each — average
        // must come out to exactly 30, not negative, not the 5-minute
        // default, and not some other stray value.
        foreach ([20, 30, 40] as $durationMinutes) {
            $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'History Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700'.rand(100000, 999999)]);
            $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'COMPLETED']);
            $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Completed', 'requires_payment' => true]);
            $startedAt = now()->subHours(2);
            QueueTicket::create([
                'service_id' => $service->id,
                'priority_level_id' => $normal->id,
                'queue_number' => 'CONS-HIST-'.$durationMinutes,
                'priority_score' => $normal->weight,
                'status' => 'COMPLETED',
                'service_started_at' => $startedAt,
                'completed_at' => $startedAt->copy()->addMinutes($durationMinutes),
            ]);
        }

        $ahead = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Ahead Of History', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000400']);
        $aheadVisit = Visit::create(['patient_id' => $ahead->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $aheadService = Service::create(['visit_id' => $aheadVisit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        $aheadTicket = QueueTicket::create(['service_id' => $aheadService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0090', 'priority_score' => $normal->weight, 'status' => 'WAITING']);
        $aheadTicket->created_at = now()->subMinutes(5);
        $aheadTicket->save();

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Behind History Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000401']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0091', 'priority_score' => $normal->weight, 'status' => 'WAITING']);

        $response = $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(200);

        // 1 ticket ahead × the real 30-minute average = 30, never negative, never the 5-minute default.
        $response->assertJsonPath('position', 1)->assertJsonPath('estimated_wait_minutes', 30);
    }

    /** No historical COMPLETED tickets to measure from yet — must still return the fixed-default estimate, not null/zero/an error. */
    public function test_estimated_wait_falls_back_to_the_default_when_no_department_history_exists(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        $ahead = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Ahead Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000300']);
        $aheadVisit = Visit::create(['patient_id' => $ahead->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $aheadService = Service::create(['visit_id' => $aheadVisit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        // created_at isn't in QueueTicket's #[Fillable] list (deliberately —
        // it's never meant to be client-set), so it must be set via a
        // separate save() after create(), not inside the create() array,
        // which would silently no-op it.
        $aheadTicket = QueueTicket::create(['service_id' => $aheadService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0001', 'priority_score' => $normal->weight, 'status' => 'WAITING']);
        $aheadTicket->created_at = now()->subMinutes(5);
        $aheadTicket->save();

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Default ETA Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000301']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0002', 'priority_score' => $normal->weight, 'status' => 'WAITING']);

        $response = $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(200);

        // 1 ticket ahead × the 5-minute fixed default (no COMPLETED history anywhere in this department).
        $response->assertJsonPath('position', 1)->assertJsonPath('estimated_wait_minutes', 5);
    }

    /**
     * DoD #3 — re-confirms the Phase 10 privacy guarantee still holds now
     * that queue_numbers_ahead exists: it must be plain ticket-number
     * strings only, never a name/contact/any other field belonging to
     * the OTHER waiting visits it's built from.
     */
    public function test_queue_numbers_ahead_never_leaks_another_visits_identifying_data(): void
    {
        $department = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        $otherPatient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Should Never Leak', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700999888']);
        $otherVisit = Visit::create(['patient_id' => $otherPatient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $otherService = Service::create(['visit_id' => $otherVisit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        $otherTicket = QueueTicket::create(['service_id' => $otherService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0050', 'priority_score' => $normal->weight, 'status' => 'WAITING']);
        $otherTicket->created_at = now()->subMinutes(30);
        $otherTicket->save();

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Tracking Owner', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111222']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0051', 'priority_score' => $normal->weight, 'status' => 'WAITING']);

        $response = $this->getJson("/api/track/{$visit->tracking_token}")->assertStatus(200);
        $response->assertJsonPath('queue_numbers_ahead', ['CONS-0050']);

        $body = $response->json();
        $this->assertSame(['CONS-0050'], $body['queue_numbers_ahead'], 'queue_numbers_ahead must contain plain strings only, not nested objects.');
        $this->assertStringNotContainsString('Should Never Leak', json_encode($body));
        $this->assertStringNotContainsString('0700999888', json_encode($body));
        $this->assertStringNotContainsString('Tracking Owner', json_encode($body));
        $this->assertStringNotContainsString('0700111222', json_encode($body));
    }
}
