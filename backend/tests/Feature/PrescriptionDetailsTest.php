<?php

namespace Tests\Feature;

use App\Models\MedicationCatalog;

/**
 * The Doctor's prescription records dosage / frequency / duration / quantity
 * per medicine plus optional prescription notes; the plain id-only
 * checklist keeps working (quantity defaults to 1).
 */
class PrescriptionDetailsTest extends NotificationTestCase
{
    public function test_prescription_details_and_notes_are_saved_and_read_back(): void
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $serviceId = $ticket->service_id;
        $para = MedicationCatalog::create(['name' => 'Paracetamol', 'price' => 300, 'active' => true]);
        $ors = MedicationCatalog::create(['name' => 'ORS', 'price' => 1500, 'active' => true]);

        $this->actingAs($this->doctor)->putJson("/api/services/{$serviceId}/prescribed-medications", [
            'medications' => [
                ['id' => $para->id, 'dosage' => '500 mg', 'frequency' => '3x daily', 'duration' => '3 days', 'quantity' => 10],
                ['id' => $ors->id],
            ],
            'other' => null,
            'notes' => 'Take medicines after food.',
        ])->assertOk();

        $this->actingAs($this->doctor)->getJson("/api/services/{$serviceId}/prescribed-medications")
            ->assertOk()
            ->assertJsonPath('notes', 'Take medicines after food.')
            ->assertJsonCount(2, 'medications')
            ->assertJsonFragment(['medication_catalog_id' => $para->id, 'dosage' => '500 mg', 'frequency' => '3x daily', 'duration' => '3 days', 'quantity' => 10])
            ->assertJsonFragment(['medication_catalog_id' => $ors->id, 'quantity' => 1]);

        $this->assertSame('Take medicines after food.', $visit->clinicalRecord()->first()->prescription_notes);
    }

    public function test_the_id_only_checklist_still_works_and_leaves_notes_alone(): void
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $serviceId = $ticket->service_id;
        $para = MedicationCatalog::create(['name' => 'Paracetamol', 'price' => 300, 'active' => true]);

        $visit->clinicalRecord()->firstOrCreate([])->update(['prescription_notes' => 'Keep me']);

        $this->actingAs($this->doctor)->putJson("/api/services/{$serviceId}/prescribed-medications", [
            'medication_catalog_ids' => [$para->id],
            'other' => 'Vitamin C',
        ])->assertOk()->assertJsonPath('medications.0.quantity', 1);

        $this->assertSame('Keep me', $visit->clinicalRecord()->first()->prescription_notes);
    }
}
