<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\ClinicalRecord;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DoD #1: a full Registration -> Doctor -> Laboratory -> Doctor ->
 * Pharmacy journey produces exactly ONE clinical_records row that
 * accumulates every section, each written only by its correct role — the
 * complete real-HTTP round trip, not a shortcut through direct model
 * writes, so the forwarding gates (Part 2/3/4) are genuinely exercised
 * along the way too.
 */
class ClinicalRecordFullJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private Department $lab;

    private Department $pharm;

    private User $registrationStaff;

    private User $doctor;

    private User $labStaff;

    private User $billingStaff;

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

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true, 'counter_number' => 2]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true, 'counter_number' => 3]);
        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true, 'counter_number' => 4]);

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');
        $this->billingStaff = $this->makeUser('Cashier/Billing Staff');
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

    private function verifyPaymentFor(Service $service): void
    {
        $this->actingAs($this->billingStaff)->postJson("/api/services/{$service->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'Fee', 'amount' => 1000]],
        ])->assertStatus(201);
    }

    public function test_full_journey_produces_one_clinical_record_accumulating_every_section(): void
    {
        // 1. Registration: create patient + visit into Consultation, with a chief complaint.
        $patientRes = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Journey Patient', 'date_of_birth' => '1985-05-05', 'gender' => 'Male', 'contact' => '0700123456',
        ])->assertStatus(201);
        $patientId = $patientRes->json('patient.id');

        $visitRes = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientId,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
            'chief_complaint' => 'Fever and headache for 2 days.',
        ])->assertStatus(201);
        $visitId = $visitRes->json('visit.id');
        $consServiceId = $visitRes->json('visit.services.0.id');
        $consTicketId = $visitRes->json('visit.services.0.queue_ticket.id');

        // 2. Doctor sees the patient: call + start service (payment first).
        $this->verifyPaymentFor(Service::find($consServiceId));
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicketId}/start-service")->assertStatus(200);

        // Doctor writes symptoms/preliminary diagnosis, ticks a checklist
        // item plus an "Others" note, decides to refer to Lab.
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'doctor_symptoms_notes' => 'Fever 38.5C, mild headache, no rash.',
            'doctor_preliminary_diagnosis' => 'Suspected malaria.',
            'referral_target' => 'laboratory',
        ])->assertStatus(200);

        $fbc = \App\Models\LabTestCatalog::create(['name' => 'CBC, Platelet Count', 'category' => 'Hematology', 'price' => 12000, 'active' => true]);
        $this->actingAs($this->doctor)->putJson("/api/services/{$consServiceId}/requested-tests", [
            'lab_test_catalog_ids' => [$fbc->id],
            'other' => 'Malaria test (MRDT).',
        ])->assertStatus(200);

        // Forward to Laboratory — gate satisfied since a checklist item is ticked.
        $labServiceRes = $this->actingAs($this->doctor)->postJson("/api/visits/{$visitId}/services", [
            'department_id' => $this->lab->id,
        ])->assertStatus(201);
        $labServiceId = $labServiceRes->json('service.id');
        $labTicketId = $labServiceRes->json('service.queue_ticket.id');

        // Laboratory Staff's read-only view resolves back to the SAME
        // checklist, even though it's asking via the Lab service's own id.
        $this->actingAs($this->labStaff)->getJson("/api/services/{$labServiceId}/requested-tests")
            ->assertStatus(200)
            ->assertJsonPath('lab_test_catalog_ids', [$fbc->id])
            ->assertJsonPath('other', 'Malaria test (MRDT).');

        // Billing's pending-payments listing pre-selects the same checklist
        // — the Lab service itself is still awaiting payment at this point.
        $pendingRes = $this->actingAs($this->billingStaff)->getJson('/api/payments/pending')->assertStatus(200);
        $labRow = collect($pendingRes->json('pending'))->firstWhere('service_id', $labServiceId);
        $this->assertSame([$fbc->id], $labRow['requested_test_catalog_ids']);
        $this->assertSame('Malaria test (MRDT).', $labRow['requested_tests_other']);

        // 3. Laboratory: call + start, record results.
        $this->verifyPaymentFor(Service::find($labServiceId));
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/call")->assertStatus(200);
        $this->actingAs($this->labStaff)->patchJson("/api/queue-tickets/{$labTicketId}/start-service")->assertStatus(200);

        $this->actingAs($this->labStaff)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'lab_results_notes' => 'MRDT: positive for P. falciparum. FBC: mild anemia.',
        ])->assertStatus(200);

        // Forward back to Doctor — gate satisfied since lab_results_notes is filled.
        $consService2Res = $this->actingAs($this->labStaff)->postJson("/api/visits/{$visitId}/services", [
            'department_id' => $this->cons->id,
        ])->assertStatus(201);
        $consService2Id = $consService2Res->json('service.id');
        $consTicket2Id = $consService2Res->json('service.queue_ticket.id');

        // 4. Doctor final review: call + start, full record visible so far.
        $this->verifyPaymentFor(Service::find($consService2Id));
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicket2Id}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicket2Id}/start-service")->assertStatus(200);

        $recordSoFar = $this->actingAs($this->doctor)->getJson("/api/visits/{$visitId}/clinical-record")->assertStatus(200);
        $recordSoFar->assertJsonPath('clinical_record.chief_complaint', 'Fever and headache for 2 days.')
            ->assertJsonPath('clinical_record.doctor_preliminary_diagnosis', 'Suspected malaria.')
            ->assertJsonPath('clinical_record.lab_results_notes', 'MRDT: positive for P. falciparum. FBC: mild anemia.');

        // The "Result" form (this SECOND Consultation service, opened once
        // Laboratory forwards the patient back) must still resolve back to
        // the checklist the Doctor originally requested on the FIRST
        // Consultation service — Service::labRequestOriginService() walks
        // the CONS->LAB->CONS chain rather than assuming this service is
        // its own origin just because it's also CONS.
        $this->actingAs($this->doctor)->getJson("/api/services/{$consService2Id}/requested-tests")
            ->assertStatus(200)
            ->assertJsonPath('lab_test_catalog_ids', [$fbc->id])
            ->assertJsonPath('other', 'Malaria test (MRDT).')
            ->assertJsonPath('tests.0.name', 'CBC, Platelet Count');

        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visitId}/clinical-record", [
            'final_diagnosis' => 'Confirmed uncomplicated malaria.',
            'treatment_plan' => 'Artemether-Lumefantrine, twice daily for 3 days.',
            'patient_signature_name' => 'Journey Patient',
            'patient_signature_phone' => '0700123456',
        ])->assertStatus(200);

        // Forward to Pharmacy — gate satisfied since final review is complete.
        $pharmServiceRes = $this->actingAs($this->doctor)->postJson("/api/visits/{$visitId}/services", [
            'department_id' => $this->pharm->id,
        ])->assertStatus(201);

        // 5. Exactly ONE clinical_records row for this visit, accumulating everything.
        $this->assertSame(1, ClinicalRecord::where('visit_id', $visitId)->count());

        $record = ClinicalRecord::where('visit_id', $visitId)->first();
        $this->assertSame('Fever and headache for 2 days.', $record->chief_complaint);
        $this->assertSame('Fever 38.5C, mild headache, no rash.', $record->doctor_symptoms_notes);
        $this->assertSame('Suspected malaria.', $record->doctor_preliminary_diagnosis);
        $this->assertSame('Malaria test (MRDT).', $record->requested_tests_other);
        $this->assertDatabaseHas('service_requested_tests', ['service_id' => $consServiceId, 'lab_test_catalog_id' => $fbc->id]);
        $this->assertSame('laboratory', $record->referral_target);
        $this->assertSame('MRDT: positive for P. falciparum. FBC: mild anemia.', $record->lab_results_notes);
        $this->assertSame($this->labStaff->id, $record->lab_technician_id);
        $this->assertSame('Confirmed uncomplicated malaria.', $record->final_diagnosis);
        $this->assertSame('Artemether-Lumefantrine, twice daily for 3 days.', $record->treatment_plan);
        $this->assertSame('Journey Patient', $record->patient_signature_name);
        $this->assertSame('0700123456', $record->patient_signature_phone);
        $this->assertNotNull($record->signed_at);

        // The pharmacy service really did get created, closing the loop.
        $this->assertSame('Pharmacy', $pharmServiceRes->json('service.department.dept_name'));
    }
}
