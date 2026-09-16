<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\QueueTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

/**
 * DoD #2: forwarding to Laboratory is blocked until at least one
 * checklist item is ticked or requested_tests_other is filled; forwarding
 * to Pharmacy (whether directly from Doctor, per Part
 * 2.3, or after Lab, per Part 4.3) is blocked until final_diagnosis,
 * treatment_plan, and both signature fields are filled; Laboratory cannot
 * forward an empty result back to Doctor (Part 3.2).
 */
class ClinicalRecordForwardingGatesTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $lab;

    private Department $pharm;

    private User $doctor;

    private User $labStaff;

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

    /** @return array{0: Visit, 1: Service} a visit with its current service IN_SERVICE at the given department. */
    private function makeVisitInService(Department $department): array
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Gate Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'IN_'.$department->dept_code]);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => $department->dept_name, 'status' => 'Active', 'requires_payment' => true]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => strtoupper($department->dept_code).'-'.uniqid(), 'priority_score' => 10, 'status' => 'IN_SERVICE']);
        $this->verifyPayment($service);

        return [$visit, $service];
    }

    public function test_forwarding_to_laboratory_is_blocked_until_requested_tests_other_is_filled(): void
    {
        [$visit] = $this->makeVisitInService($this->cons);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(422);

        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$visit->id}/clinical-record", ['requested_tests_other' => 'Malaria test, FBC.'])
            ->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(201);
    }

    /** The OTHER way to satisfy the same gate — ticking at least one catalog checklist item, no "Others" text needed. */
    public function test_forwarding_to_laboratory_is_also_satisfied_by_a_ticked_checklist_item(): void
    {
        [$visit, $service] = $this->makeVisitInService($this->cons);
        $test = \App\Models\LabTestCatalog::create(['name' => 'CBC, Platelet Count', 'category' => 'Hematology', 'price' => 12000, 'active' => true]);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(422);

        $this->actingAs($this->doctor)
            ->putJson("/api/services/{$service->id}/requested-tests", ['lab_test_catalog_ids' => [$test->id]])
            ->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(201);
    }

    public function test_forwarding_directly_to_pharmacy_is_blocked_until_final_fields_are_filled(): void
    {
        [$visit] = $this->makeVisitInService($this->cons);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(422);

        // Partially filled — still blocked.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'final_diagnosis' => 'Confirmed condition.',
            'treatment_plan' => 'Medication X for 5 days.',
        ])->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(422, 'Missing patient signature must still block forwarding to Pharmacy.');

        // Fully filled — now it succeeds.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'patient_signature_name' => 'Patient Name',
            'patient_signature_phone' => '0700555666',
        ])->assertStatus(200);

        $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(201);
    }

    public function test_laboratory_forwarding_to_doctor_is_blocked_until_lab_results_notes_is_filled(): void
    {
        [$visit] = $this->makeVisitInService($this->lab);

        $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(422);

        $this->actingAs($this->labStaff)
            ->patchJson("/api/visits/{$visit->id}/clinical-record", ['lab_results_notes' => 'Malaria: positive.'])
            ->assertStatus(200);

        $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->cons->id])
            ->assertStatus(201);
    }

    /** Part 3.3: Laboratory sending directly to Pharmacy still goes through the SAME "ready for pharmacy" gate as everyone else — lab_results_notes alone is not sufficient. */
    public function test_laboratory_sending_directly_to_pharmacy_still_requires_the_doctor_final_fields(): void
    {
        [$visit] = $this->makeVisitInService($this->lab);

        $this->actingAs($this->labStaff)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'lab_results_notes' => 'All normal.',
        ])->assertStatus(200);

        // lab_results_notes alone does not satisfy the Pharmacy gate — the
        // doctor's final diagnosis/treatment/signature must already exist
        // (filled before referring to Lab, in this workflow variant).
        $this->actingAs($this->labStaff)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->pharm->id])
            ->assertStatus(422);
    }
}
