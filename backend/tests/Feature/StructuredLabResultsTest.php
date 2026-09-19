<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\LabTestCatalog;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\ServiceRequestedTest;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

/**
 * Doctor Role expansion — per-test structured lab results
 * (RequestedTestController::updateResults). lab_results_notes stays as an
 * optional overall summary alongside these, not replaced.
 */
class StructuredLabResultsTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $lab;

    private User $doctor;

    private User $labStaff;

    private Service $consService;

    private Service $labService;

    private ServiceRequestedTest $requestedTest;

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

        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');

        $patient = Patient::create([
            'name' => 'Lab Results Patient', 'date_of_birth' => '1992-02-02', 'gender' => 'Female',
            'contact' => '0700000003', 'patient_number' => 'P-LAB-1',
        ]);

        $visitRes = $this->actingAs($this->doctor)->postJson('/api/visits', [
            'patient_id' => $patient->id, 'patient_type' => 'Normal', 'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $this->consService = Service::find($visitRes->json('visit.services.0.id'));
        $consTicketId = $visitRes->json('visit.services.0.queue_ticket.id');
        $this->verifyPayment($this->consService);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/start-service")->assertStatus(200);

        $fbc = LabTestCatalog::create(['name' => 'FBC', 'category' => 'Hematology', 'price' => 10000, 'active' => true]);
        $this->actingAs($this->doctor)->putJson("/api/services/{$this->consService->id}/requested-tests", [
            'lab_test_catalog_ids' => [$fbc->id],
        ])->assertStatus(200);
        $this->requestedTest = ServiceRequestedTest::where('service_id', $this->consService->id)->firstOrFail();

        $labServiceRes = $this->actingAs($this->doctor)->postJson('/api/visits/'.$this->consService->visit_id.'/services', [
            'department_id' => $this->lab->id,
        ])->assertStatus(201);
        $this->labService = Service::find($labServiceRes->json('service.id'));
        $labTicketId = $labServiceRes->json('service.queue_ticket.id');

        $this->verifyPayment($this->labService);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/call")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/start-service")->assertStatus(200);
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

    public function test_laboratory_staff_can_record_per_test_structured_results(): void
    {
        $res = $this->actingAs($this->labStaff)->putJson("/api/services/{$this->labService->id}/requested-tests/results", [
            'results' => [
                ['id' => $this->requestedTest->id, 'result_value' => '13.2 g/dL', 'reference_range' => '13-17', 'status' => 'Normal'],
            ],
        ])->assertStatus(200);

        $this->assertSame('13.2 g/dL', $res->json('tests.0.result_value'));
        $this->assertSame('Normal', $res->json('tests.0.status'));

        $this->assertDatabaseHas('service_requested_tests', [
            'id' => $this->requestedTest->id,
            'result_value' => '13.2 g/dL',
            'reference_range' => '13-17',
            'status' => 'Normal',
        ]);
    }

    public function test_results_cannot_be_set_on_rows_belonging_to_a_different_service(): void
    {
        $otherPatient = Patient::create([
            'name' => 'Other Patient', 'date_of_birth' => '1988-08-08', 'gender' => 'Male',
            'contact' => '0700000004', 'patient_number' => 'P-LAB-2',
        ]);
        $otherVisitRes = $this->actingAs($this->doctor)->postJson('/api/visits', [
            'patient_id' => $otherPatient->id, 'patient_type' => 'Normal', 'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);
        $otherConsService = Service::find($otherVisitRes->json('visit.services.0.id'));
        $otherConsTicketId = $otherVisitRes->json('visit.services.0.queue_ticket.id');
        $this->verifyPayment($otherConsService);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$otherConsTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$otherConsTicketId}/start-service")->assertStatus(200);

        $otherFbc = LabTestCatalog::create(['name' => 'Urinalysis', 'category' => 'Urine', 'price' => 5000, 'active' => true]);
        $this->actingAs($this->doctor)->putJson("/api/services/{$otherConsService->id}/requested-tests", [
            'lab_test_catalog_ids' => [$otherFbc->id],
        ])->assertStatus(200);
        $otherRequestedTest = ServiceRequestedTest::where('service_id', $otherConsService->id)->firstOrFail();

        // Attempt to write a result for the OTHER visit's requested-test row
        // through THIS visit's lab service — must be rejected.
        $this->actingAs($this->labStaff)->putJson("/api/services/{$this->labService->id}/requested-tests/results", [
            'results' => [
                ['id' => $otherRequestedTest->id, 'result_value' => 'Tampered'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('service_requested_tests', ['id' => $otherRequestedTest->id, 'result_value' => 'Tampered']);
    }

    public function test_forwarding_lab_to_doctor_is_satisfied_by_a_structured_result_even_without_lab_results_notes(): void
    {
        // No lab_results_notes ever set — only the structured result below.
        $this->actingAs($this->labStaff)->putJson("/api/services/{$this->labService->id}/requested-tests/results", [
            'results' => [
                ['id' => $this->requestedTest->id, 'result_value' => '13.2 g/dL', 'status' => 'Normal'],
            ],
        ])->assertStatus(200);

        $this->actingAs($this->labStaff)->postJson('/api/visits/'.$this->consService->visit_id.'/services', [
            'department_id' => $this->cons->id,
        ])->assertStatus(201);
    }

    public function test_non_laboratory_roles_cannot_set_results(): void
    {
        $this->actingAs($this->doctor)->putJson("/api/services/{$this->labService->id}/requested-tests/results", [
            'results' => [
                ['id' => $this->requestedTest->id, 'result_value' => 'Should be rejected'],
            ],
        ])->assertStatus(403);
    }
}
