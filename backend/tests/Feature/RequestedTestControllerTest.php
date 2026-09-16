<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\LabTestCatalog;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Structured Laboratory Request Form — the categorized checklist
 * (service_requested_tests) that replaced clinical_records.requested_tests
 * free text. Keyed by the REQUESTING Consultation service; a Laboratory
 * service resolves back to it via Service::labRequestOriginService.
 */
class RequestedTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private Department $lab;

    private User $doctor;

    private User $labStaff;

    private LabTestCatalog $cbc;

    private LabTestCatalog $pt;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');

        $this->cbc = LabTestCatalog::create(['name' => 'CBC, Platelet Count', 'category' => 'Hematology', 'price' => 12000, 'active' => true]);
        $this->pt = LabTestCatalog::create(['name' => 'PT with INR', 'category' => 'Coagulation Tests', 'price' => 15000, 'active' => true]);
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

    private function makeConsultationService(): Service
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Checklist Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'IN_CONS']);

        return Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Active', 'requires_payment' => true]);
    }

    public function test_doctor_can_set_the_checklist_and_others_text_on_their_consultation_service(): void
    {
        $service = $this->makeConsultationService();

        $this->actingAs($this->doctor)->putJson("/api/services/{$service->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->cbc->id, $this->pt->id],
            'other' => 'Custom unlisted test',
        ])
            ->assertStatus(200)
            ->assertJsonPath('other', 'Custom unlisted test');

        $this->assertDatabaseHas('service_requested_tests', ['service_id' => $service->id, 'lab_test_catalog_id' => $this->cbc->id]);
        $this->assertDatabaseHas('service_requested_tests', ['service_id' => $service->id, 'lab_test_catalog_id' => $this->pt->id]);
        $this->assertSame('Custom unlisted test', $service->visit->clinicalRecord->requested_tests_other);
    }

    public function test_non_doctor_cannot_set_the_checklist(): void
    {
        $service = $this->makeConsultationService();

        $this->actingAs($this->labStaff)->putJson("/api/services/{$service->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->cbc->id],
        ])->assertStatus(403);
    }

    public function test_the_checklist_cannot_be_set_on_a_non_consultation_service(): void
    {
        $service = $this->makeConsultationService();
        $labService = Service::create(['visit_id' => $service->visit_id, 'department_id' => $this->lab->id, 'previous_service_id' => $service->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);

        $this->actingAs($this->doctor)->putJson("/api/services/{$labService->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->cbc->id],
        ])->assertStatus(422);
    }

    /** Re-submitting replaces the checklist outright — same "replace, don't append" convention as PaymentController::verify's items. */
    public function test_resubmitting_the_checklist_replaces_it_rather_than_appending(): void
    {
        $service = $this->makeConsultationService();

        $this->actingAs($this->doctor)->putJson("/api/services/{$service->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->cbc->id, $this->pt->id],
        ])->assertStatus(200);

        $this->actingAs($this->doctor)->putJson("/api/services/{$service->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->pt->id],
        ])->assertStatus(200);

        $this->assertSame(1, \App\Models\ServiceRequestedTest::where('service_id', $service->id)->count());
        $this->assertDatabaseMissing('service_requested_tests', ['service_id' => $service->id, 'lab_test_catalog_id' => $this->cbc->id]);
    }

    public function test_a_laboratory_service_resolves_the_checklist_back_to_its_requesting_consultation_service(): void
    {
        $service = $this->makeConsultationService();
        $this->actingAs($this->doctor)->putJson("/api/services/{$service->id}/requested-tests", [
            'lab_test_catalog_ids' => [$this->cbc->id],
            'other' => 'Peripheral smear too',
        ])->assertStatus(200);

        $labService = Service::create(['visit_id' => $service->visit_id, 'department_id' => $this->lab->id, 'previous_service_id' => $service->id, 'service_type' => 'Laboratory', 'status' => 'Active', 'requires_payment' => true]);

        $this->actingAs($this->labStaff)->getJson("/api/services/{$labService->id}/requested-tests")
            ->assertStatus(200)
            ->assertJsonPath('lab_test_catalog_ids', [$this->cbc->id])
            ->assertJsonPath('other', 'Peripheral smear too');
    }

    public function test_doctor_and_laboratory_staff_can_view_the_lab_test_catalog(): void
    {
        $this->actingAs($this->doctor)->getJson('/api/lab-test-catalog')->assertStatus(200);
        $this->actingAs($this->labStaff)->getJson('/api/lab-test-catalog')->assertStatus(200);
    }

    public function test_administrator_can_set_a_category_when_creating_a_catalog_item(): void
    {
        $admin = $this->makeUser('Administrator');

        $this->actingAs($admin)->postJson('/api/lab-test-catalog', [
            'name' => 'Ferritin', 'category' => 'Hematology', 'price' => 20000,
        ])
            ->assertStatus(201)
            ->assertJsonPath('lab_test.category', 'Hematology');
    }
}
