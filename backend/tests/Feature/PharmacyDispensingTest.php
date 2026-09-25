<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MedicationCatalog;
use App\Models\QueueTicket;
use App\Models\ServicePrescribedMedication;

/**
 * Pharmacy dispensing form: the pharmacist marks which prescribed medicines
 * were handed over (and how many); the patient's signature is required
 * before dispensing can be completed.
 */
class PharmacyDispensingTest extends NotificationTestCase
{
    private Department $pharm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true]);
    }

    /** A Pharmacy ticket already IN_SERVICE (paid, called, started) with two prescribed medicines. */
    private function inServicePharmacyTicket(): array
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->pharm, requiresPayment: true);
        $pharmacist = $this->makeUser('Pharmacy Staff');

        $this->verifyPayment($ticket->service);
        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertOk();
        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticket->id}/start-service")->assertOk();

        $para = MedicationCatalog::create(['name' => 'Paracetamol', 'price' => 300, 'active' => true]);
        $ors = MedicationCatalog::create(['name' => 'ORS', 'price' => 1500, 'active' => true]);
        ServicePrescribedMedication::create(['service_id' => $ticket->service_id, 'medication_catalog_id' => $para->id, 'quantity' => 10]);
        ServicePrescribedMedication::create(['service_id' => $ticket->service_id, 'medication_catalog_id' => $ors->id, 'quantity' => 5]);

        return [$visit, $ticket, $pharmacist, $para, $ors];
    }

    public function test_pharmacist_marks_medicines_dispensed_and_can_undo(): void
    {
        [, $ticket, $pharmacist, $para, $ors] = $this->inServicePharmacyTicket();

        $this->actingAs($pharmacist)->putJson("/api/services/{$ticket->service_id}/prescribed-medications/dispensing", [
            'items' => [
                ['medication_catalog_id' => $para->id, 'dispensed_quantity' => 10],
                ['medication_catalog_id' => $ors->id, 'dispensed_quantity' => null],
            ],
        ])->assertOk();

        $this->actingAs($pharmacist)->getJson("/api/services/{$ticket->service_id}/prescribed-medications")
            ->assertOk()
            ->assertJsonFragment(['medication_catalog_id' => $para->id, 'dispensed_quantity' => 10]);

        $this->assertNotNull(ServicePrescribedMedication::where('medication_catalog_id', $para->id)->first()->dispensed_at);
        $this->assertNull(ServicePrescribedMedication::where('medication_catalog_id', $ors->id)->first()->dispensed_at);

        // Un-mark.
        $this->actingAs($pharmacist)->putJson("/api/services/{$ticket->service_id}/prescribed-medications/dispensing", [
            'items' => [['medication_catalog_id' => $para->id, 'dispensed_quantity' => 0]],
        ])->assertOk();
        $this->assertNull(ServicePrescribedMedication::where('medication_catalog_id', $para->id)->first()->dispensed_at);
    }

    public function test_only_pharmacy_staff_may_record_dispensing_and_only_for_prescribed_medicines(): void
    {
        [, $ticket, $pharmacist] = $this->inServicePharmacyTicket();
        $stranger = MedicationCatalog::create(['name' => 'Not Prescribed', 'price' => 100, 'active' => true]);

        $this->actingAs($this->doctor)->putJson("/api/services/{$ticket->service_id}/prescribed-medications/dispensing", ['items' => []])->assertStatus(403);

        $this->actingAs($pharmacist)->putJson("/api/services/{$ticket->service_id}/prescribed-medications/dispensing", [
            'items' => [['medication_catalog_id' => $stranger->id, 'dispensed_quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_dispensing_cannot_be_completed_until_the_patient_has_signed(): void
    {
        [$visit, $ticket, $pharmacist] = $this->inServicePharmacyTicket();

        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticket->id}/complete")->assertStatus(422);
        $this->assertSame('IN_SERVICE', QueueTicket::find($ticket->id)->status);

        $this->actingAs($pharmacist)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'dispensing_signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ])->assertOk();
        $this->assertNotNull($visit->clinicalRecord()->first()->dispensing_signed_at);

        $this->actingAs($pharmacist)->patchJson("/api/queue-tickets/{$ticket->id}/complete")->assertOk();
        $this->assertSame('COMPLETED', QueueTicket::find($ticket->id)->status);
    }

    public function test_only_pharmacy_staff_may_write_the_dispensing_signature(): void
    {
        [$visit] = $this->inServicePharmacyTicket();

        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'dispensing_signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ])->assertStatus(403);
    }
}
