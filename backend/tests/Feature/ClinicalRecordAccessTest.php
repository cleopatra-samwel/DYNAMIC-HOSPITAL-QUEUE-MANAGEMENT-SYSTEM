<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\ClinicalRecord;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DoD #1: each clinical_records section is writable only by its
 * responsible role, enforced in ClinicalRecordController — a Laboratory
 * Staff attempting to write final_diagnosis (a Doctor-only field) is
 * rejected, etc. Administrator can always write any section too, matching
 * the same "specific role OR Administrator" pattern every other gated
 * action in this app already uses (PaymentController, InsuranceCardController).
 */
class ClinicalRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private Department $lab;

    private User $registrationStaff;

    private User $doctor;

    private User $labStaff;

    private User $pharmacyStaff;

    private User $admin;

    private Visit $visit;

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

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');
        $this->pharmacyStaff = $this->makeUser('Pharmacy Staff');
        $this->admin = $this->makeUser('Administrator');

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Clinical Record Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $this->visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        Service::create(['visit_id' => $this->visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Pending', 'requires_payment' => true]);
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

    public function test_registration_staff_can_write_chief_complaint(): void
    {
        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['chief_complaint' => 'Persistent headache for 3 days.'])
            ->assertStatus(200)
            ->assertJsonPath('clinical_record.chief_complaint', 'Persistent headache for 3 days.');
    }

    public function test_registration_staff_cannot_write_doctor_fields(): void
    {
        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['doctor_preliminary_diagnosis' => 'Should not work.'])
            ->assertStatus(403);
    }

    public function test_doctor_can_write_their_consultation_fields(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", [
                'doctor_symptoms_notes' => 'Fever, mild cough.',
                'doctor_preliminary_diagnosis' => 'Suspected malaria.',
                'requested_tests_other' => 'Malaria test, full blood count.',
                'referral_target' => 'laboratory',
            ])
            ->assertStatus(200)
            ->assertJsonPath('clinical_record.referral_target', 'laboratory')
            ->assertJsonPath('clinical_record.requested_tests_other', 'Malaria test, full blood count.');
    }

    public function test_doctor_cannot_write_lab_results_notes(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['lab_results_notes' => 'Should not work.'])
            ->assertStatus(403);
    }

    /** Laboratory Staff attempting to write final_diagnosis (a Doctor-only field) is rejected — the exact DoD #1 example. */
    public function test_laboratory_staff_cannot_write_final_diagnosis(): void
    {
        $this->actingAs($this->labStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['final_diagnosis' => 'Should not work.'])
            ->assertStatus(403);

        $this->assertNull($this->visit->clinicalRecord()->first()?->final_diagnosis);
    }

    public function test_laboratory_staff_can_write_lab_results_notes_and_it_records_who(): void
    {
        $response = $this->actingAs($this->labStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['lab_results_notes' => 'Malaria: negative. FBC: normal.'])
            ->assertStatus(200);

        $response->assertJsonPath('clinical_record.lab_results_notes', 'Malaria: negative. FBC: normal.');
        $this->assertSame($this->labStaff->id, $response->json('clinical_record.lab_technician_id'));
    }

    /** lab_technician_id is never client-writable — always derived from whoever actually recorded the results. */
    public function test_lab_technician_id_cannot_be_spoofed_by_the_client(): void
    {
        $otherLabStaff = $this->makeUser('Laboratory Staff');

        $response = $this->actingAs($this->labStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", [
                'lab_results_notes' => 'Results here.',
                'lab_technician_id' => $otherLabStaff->id,
            ])
            ->assertStatus(200);

        $this->assertSame($this->labStaff->id, $response->json('clinical_record.lab_technician_id'), 'lab_technician_id must reflect who actually recorded it, not a client-supplied value.');
    }

    public function test_doctor_can_write_final_review_fields(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", [
                'final_diagnosis' => 'Confirmed malaria.',
                'treatment_plan' => 'Artemether-Lumefantrine, 3 days.',
                'patient_signature_name' => 'John Juma',
                'patient_signature_phone' => '0700111222',
            ])
            ->assertStatus(200)
            ->assertJsonPath('clinical_record.final_diagnosis', 'Confirmed malaria.');
    }

    /** Part 4.2: signed_at is set the moment both signature fields are actually filled together — not a separately client-set flag. */
    public function test_signed_at_is_set_automatically_when_both_signature_fields_are_filled(): void
    {
        $before = now();

        $response = $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", [
                'patient_signature_name' => 'Jane Doe',
                'patient_signature_phone' => '0700333444',
            ])
            ->assertStatus(200);

        $this->assertNotNull($response->json('clinical_record.signed_at'));
        $this->assertGreaterThanOrEqual($before->timestamp, \Carbon\Carbon::parse($response->json('clinical_record.signed_at'))->timestamp);
    }

    public function test_signed_at_stays_null_if_only_one_signature_field_is_provided(): void
    {
        $response = $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['patient_signature_name' => 'Only Name'])
            ->assertStatus(200);

        $this->assertNull($response->json('clinical_record.signed_at'));
    }

    /** Submitting a mix of an authorized field and an unauthorized field must reject the WHOLE request, not partially apply it. */
    public function test_a_mixed_request_with_one_unauthorized_field_is_rejected_entirely(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", [
                'doctor_preliminary_diagnosis' => 'Should not be saved either.',
                'lab_results_notes' => 'Not the doctor\'s field.',
            ])
            ->assertStatus(403);

        $this->assertNull($this->visit->clinicalRecord()->first()?->doctor_preliminary_diagnosis, 'No field should have been written when the request contained an unauthorized one.');
    }

    public function test_administrator_may_write_any_section(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['final_diagnosis' => 'Admin correction.'])
            ->assertStatus(200)
            ->assertJsonPath('clinical_record.final_diagnosis', 'Admin correction.');
    }

    public function test_pharmacy_staff_cannot_write_any_clinical_record_field(): void
    {
        $this->actingAs($this->pharmacyStaff)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['chief_complaint' => 'Should not work.'])
            ->assertStatus(403);
    }

    public function test_view_requires_the_role_permitted_on_the_visits_current_department_or_administrator(): void
    {
        // Pharmacy Staff has no business on a visit currently at Consultation.
        $this->actingAs($this->pharmacyStaff)
            ->getJson("/api/visits/{$this->visit->id}/clinical-record")
            ->assertStatus(403);

        $this->actingAs($this->doctor)
            ->getJson("/api/visits/{$this->visit->id}/clinical-record")
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->getJson("/api/visits/{$this->visit->id}/clinical-record")
            ->assertStatus(200);
    }

    public function test_referral_target_only_accepts_the_three_known_values(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['referral_target' => 'somewhere-else'])
            ->assertStatus(422);
    }

    public function test_unrecognized_fields_are_rejected(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/visits/{$this->visit->id}/clinical-record", ['not_a_real_field' => 'x'])
            ->assertStatus(422);
    }

    /** VisitRegistrationService creates the clinical_records row automatically — confirmed via the real /api/visits endpoint, not assumed. */
    public function test_a_clinical_record_is_created_automatically_with_the_visit(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Auto Record Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700999000']);

        $response = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
            'chief_complaint' => 'Sore throat.',
        ])->assertStatus(201);

        $visitId = $response->json('visit.id');
        $record = ClinicalRecord::where('visit_id', $visitId)->first();

        $this->assertNotNull($record, 'A clinical_records row must exist immediately after visit creation.');
        $this->assertSame('Sore throat.', $record->chief_complaint);
    }
}
