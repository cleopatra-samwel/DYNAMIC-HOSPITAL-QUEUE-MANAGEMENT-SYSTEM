<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Visit;

/**
 * GET /api/visits — no dedicated coverage existed before this (only
 * creation was tested elsewhere). Added alongside the Patients page's new
 * "View Visits" modal, which filters this same endpoint by patient_id
 * rather than a second, duplicated listing query.
 */
class VisitListingTest extends NotificationTestCase
{
    public function test_patient_id_filters_to_exactly_that_patients_visits(): void
    {
        $patientA = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Patient A', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000501']);
        $patientB = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Patient B', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000502']);

        $visitA1 = Visit::create(['patient_id' => $patientA->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        $visitA2 = Visit::create(['patient_id' => $patientA->id, 'visit_date' => now()->subDay()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'COMPLETED']);
        Visit::create(['patient_id' => $patientB->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);

        $response = $this->actingAs($this->registrationStaff)
            ->getJson("/api/visits?patient_id={$patientA->id}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($visitA1->id));
        $this->assertTrue($ids->contains($visitA2->id));
    }

    public function test_existing_filters_are_unaffected_by_the_new_patient_id_filter(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Filter Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000503']);
        Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Emergency', 'emergency_confirmed' => true, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);
        Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);

        $response = $this->actingAs($this->registrationStaff)
            ->getJson('/api/visits?patient_type=Emergency')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Emergency', $response->json('data.0.patient_type'));
    }

    public function test_visits_index_requires_authentication(): void
    {
        $this->getJson('/api/visits')->assertStatus(401);
    }
}
