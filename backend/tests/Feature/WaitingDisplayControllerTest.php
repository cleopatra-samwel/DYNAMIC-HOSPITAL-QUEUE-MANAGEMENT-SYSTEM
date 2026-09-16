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

class WaitingDisplayControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiting_display_is_public_and_carries_no_patient_identity(): void
    {
        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $department = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Kiosk Privacy Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000096']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_LAB']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'LAB-0099', 'priority_score' => 10, 'status' => 'WAITING']);

        // No Authorization header at all.
        $response = $this->getJson("/api/waiting-display/{$department->id}")->assertStatus(200);

        $response->assertJsonPath('department_name', 'Laboratory')
            ->assertJsonPath('tickets.0.queue_number', 'LAB-0099')
            ->assertJsonPath('tickets.0.status', 'WAITING');

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('Kiosk Privacy Patient', $body);
        $this->assertStringNotContainsString('0700000096', $body);
        $this->assertArrayNotHasKey('priority_score', $response->json('tickets.0'));
    }

    /** Same reasoning as TrackingControllerTest's rate-limit test — this is the other endpoint with no authentication at all. */
    public function test_waiting_display_endpoint_is_rate_limited_to_30_requests_per_minute(): void
    {
        $department = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/waiting-display/{$department->id}")->assertStatus(200);
        }

        $response = $this->getJson("/api/waiting-display/{$department->id}")->assertStatus(429);

        // The 429 body must never leak file paths, exception class, or a
        // stack trace — phpunit.xml doesn't override APP_DEBUG, so this
        // runs with the same APP_DEBUG=true as local .env, proving the
        // custom render() in bootstrap/app.php holds even in debug mode.
        $this->assertTrue(config('app.debug'), 'This assertion is only meaningful if APP_DEBUG is true during the test.');
        $retryAfter = $response->json('retry_after');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan(0, $retryAfter);
        $response->assertExactJson(['message' => 'Too Many Attempts.', 'retry_after' => $retryAfter]);
    }
}
