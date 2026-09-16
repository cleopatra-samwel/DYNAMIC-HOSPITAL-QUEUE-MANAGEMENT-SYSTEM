<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Patient;
use App\Models\QueueTicket;
use App\Models\Service;

/**
 * Optional doctor selection at registration (Part 1) — end-to-end HTTP
 * coverage for both paths that create a Consultation service with a
 * doctor preference: the regular New Patient form (VisitController::store)
 * and Registration Staff's self-check-in handoff
 * (ServiceFlowController::store, once the visit is at Registration), both
 * ultimately storing doctor_id the same way.
 */
class VisitDoctorSelectionTest extends NotificationTestCase
{
    protected Department $reg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reg = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true]);
    }

    public function test_registering_a_visit_with_a_valid_doctor_stores_it_on_the_service(): void
    {
        $doctor = $this->makeUser('Doctor');
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Doctor Pref Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111000']);

        $response = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
            'doctor_id' => $doctor->id,
        ])->assertStatus(201);

        $serviceId = $response->json('visit.services.0.id');
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'doctor_id' => $doctor->id]);
    }

    public function test_registering_a_visit_with_no_doctor_preference_leaves_it_null(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'No Pref Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111001']);

        $response = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $serviceId = $response->json('visit.services.0.id');
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'doctor_id' => null]);
    }

    public function test_a_doctor_id_belonging_to_a_non_doctor_user_is_rejected(): void
    {
        $notADoctor = $this->makeUser('Laboratory Staff');
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Bad Doctor Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111002']);

        $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
            'doctor_id' => $notADoctor->id,
        ])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    }

    /** doctor_id is only meaningful for Consultation — VisitRegistrationService silently drops it for any other department rather than storing nonsensical data. */
    public function test_a_doctor_id_submitted_for_a_non_consultation_department_is_ignored(): void
    {
        $doctor = $this->makeUser('Doctor');
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Lab Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111003']);

        $response = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->lab->id,
            'doctor_id' => $doctor->id,
        ])->assertStatus(201);

        $serviceId = $response->json('visit.services.0.id');
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'doctor_id' => null]);
    }

    public function test_registration_staffs_handoff_to_consultation_with_a_doctor_preference_stores_it_on_the_new_service(): void
    {
        $doctor = $this->makeUser('Doctor');

        $checkInRes = $this->postJson('/api/check-ins', [
            'name' => 'CheckIn Doctor Pref', 'date_of_birth' => '1985-01-01', 'gender' => 'Female', 'contact' => '0700111004',
        ])->assertStatus(201);

        $ticket = QueueTicket::where('queue_number', $checkInRes->json('queue_number'))->firstOrFail();
        $this->actingAs($this->registrationStaff)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertStatus(200);
        $this->actingAs($this->registrationStaff)->patchJson("/api/queue-tickets/{$ticket->id}/start-service")->assertStatus(200);

        $visit = $ticket->service->visit;
        $this->actingAs($this->registrationStaff)->patchJson("/api/visits/{$visit->id}/registration-details", [
            'patient_type' => 'Normal', 'payment_method' => 'Cash',
        ])->assertStatus(200);

        $response = $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit->id}/services", [
            'department_id' => $this->cons->id,
            'doctor_id' => $doctor->id,
        ])->assertStatus(201);

        $serviceId = $response->json('service.id');
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'doctor_id' => $doctor->id]);
    }

    public function test_registering_without_doctor_id_still_works_for_non_consultation_departments(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Lab Only Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700111005']);

        $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patient->id,
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->lab->id,
        ])->assertStatus(201);

        $this->assertSame(1, Service::where('department_id', $this->lab->id)->count());
    }
}
