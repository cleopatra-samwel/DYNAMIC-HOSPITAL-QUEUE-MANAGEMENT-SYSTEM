<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServicePrescribedMedication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Doctor's medication prescription checklist — one row per ticked
 * catalog item (service_prescribed_medications), plus a free-text "Others"
 * line kept on clinical_records itself (prescribed_medications_other),
 * mirroring the Laboratory request checklist (RequestedTestController)
 * exactly.
 *
 * Always keyed by the PRESCRIBING Consultation service — see
 * Service::pharmacyRequestOriginService() for how a Pharmacy service
 * (Pharmacy Staff's read-only view, Billing's pre-selection) resolves back
 * to it.
 */
class PrescribedMedicationController extends Controller
{
    public function index(Request $request, Service $service)
    {
        $origin = $service->pharmacyRequestOriginService();
        $origin->loadMissing('visit.clinicalRecord');

        return response()->json([
            'medication_catalog_ids' => $origin->prescribedMedications()->pluck('medication_catalog_id'),
            'medications' => $origin->prescribedMedications()->get(['medication_catalog_id', 'dosage', 'frequency', 'duration', 'quantity', 'dispensed_quantity', 'dispensed_at']),
            'other' => $origin->visit->clinicalRecord?->prescribed_medications_other,
            'notes' => $origin->visit->clinicalRecord?->prescription_notes,
        ]);
    }

    /**
     * Pharmacy Staff-only, on the Pharmacy service: marks which prescribed
     * medicines were actually handed over and how many. A null / 0
     * dispensed_quantity un-marks a medicine. Only rows of THIS service's
     * own prescription (resolved via pharmacyRequestOriginService) can be
     * touched.
     */
    public function dispense(Request $request, Service $service)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Pharmacy Staff', 'Administrator']),
            403,
            'Only Pharmacy Staff may record dispensing.'
        );

        $service->loadMissing('department');
        abort_unless(
            $service->department?->dept_code === 'PHARM',
            422,
            'Dispensing can only be recorded on a Pharmacy service.'
        );

        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.medication_catalog_id' => ['required', 'integer'],
            'items.*.dispensed_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $origin = $service->pharmacyRequestOriginService();
        $rows = $origin->prescribedMedications()->get()->keyBy('medication_catalog_id');

        DB::transaction(function () use ($data, $rows) {
            foreach ($data['items'] as $item) {
                $row = $rows->get($item['medication_catalog_id']);
                abort_unless($row, 422, 'One or more medicines are not part of this prescription.');

                $quantity = $item['dispensed_quantity'] ?? null;
                $row->update([
                    'dispensed_quantity' => $quantity ?: null,
                    'dispensed_at' => $quantity ? now() : null,
                ]);
            }
        });

        return response()->json([
            'medications' => $origin->prescribedMedications()->get(['medication_catalog_id', 'dosage', 'frequency', 'duration', 'quantity', 'dispensed_quantity', 'dispensed_at']),
        ]);
    }

    /** Doctor-only, and only on their own Consultation service — replaces the full checklist outright, same "replace, don't append" pattern as RequestedTestController::update. */
    public function update(Request $request, Service $service)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Doctor', 'Administrator']),
            403,
            'Only Doctor may prescribe medication.'
        );

        $service->loadMissing('department');
        abort_unless(
            $service->department?->dept_code === 'CONS',
            422,
            'Medication can only be prescribed on a Consultation service.'
        );

        abort_unless($request->has('medications') || $request->has('medication_catalog_ids'), 422, 'Send either medications or medication_catalog_ids.');

        $data = $request->validate([
            // Plain checklist (ids only) — kept as is for the original form.
            'medication_catalog_ids' => ['sometimes', 'array'],
            'medication_catalog_ids.*' => ['integer', 'exists:medication_catalog,id'],
            // Prescription with dosage / frequency / duration / quantity per medicine.
            'medications' => ['sometimes', 'array'],
            'medications.*.id' => ['required', 'integer', 'exists:medication_catalog,id'],
            'medications.*.dosage' => ['nullable', 'string', 'max:255'],
            'medications.*.frequency' => ['nullable', 'string', 'max:255'],
            'medications.*.duration' => ['nullable', 'string', 'max:255'],
            'medications.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'other' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // One normalised list of [catalog id => details], whichever shape was sent.
        $lines = collect($data['medications'] ?? [])->keyBy('id')->map(fn ($m) => [
            'dosage' => $m['dosage'] ?? null,
            'frequency' => $m['frequency'] ?? null,
            'duration' => $m['duration'] ?? null,
            'quantity' => $m['quantity'] ?? 1,
        ]);
        foreach (array_unique($data['medication_catalog_ids'] ?? []) as $catalogId) {
            if (! $lines->has($catalogId)) {
                $lines->put($catalogId, ['dosage' => null, 'frequency' => null, 'duration' => null, 'quantity' => 1]);
            }
        }

        DB::transaction(function () use ($service, $data, $lines, $request) {
            $service->prescribedMedications()->delete();

            foreach ($lines as $catalogId => $details) {
                ServicePrescribedMedication::create(['service_id' => $service->id, 'medication_catalog_id' => $catalogId] + $details);
            }

            $service->visit->clinicalRecord()->firstOrCreate([])->update(array_filter([
                'prescribed_medications_other' => $data['other'] ?? null,
                // Only touched when the caller actually sent notes.
                'prescription_notes' => $request->has('notes') ? ($data['notes'] ?? null) : false,
            ], fn ($value) => $value !== false));
        });

        return response()->json([
            'medication_catalog_ids' => $service->prescribedMedications()->pluck('medication_catalog_id'),
            'medications' => $service->prescribedMedications()->get(['medication_catalog_id', 'dosage', 'frequency', 'duration', 'quantity']),
            'other' => $data['other'] ?? null,
            'notes' => $service->visit->clinicalRecord()->first()?->prescription_notes,
        ]);
    }
}
