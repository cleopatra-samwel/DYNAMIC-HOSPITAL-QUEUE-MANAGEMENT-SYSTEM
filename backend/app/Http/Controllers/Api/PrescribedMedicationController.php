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
            'other' => $origin->visit->clinicalRecord?->prescribed_medications_other,
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

        $data = $request->validate([
            'medication_catalog_ids' => ['present', 'array'],
            'medication_catalog_ids.*' => ['integer', 'exists:medication_catalog,id'],
            'other' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($service, $data) {
            $service->prescribedMedications()->delete();

            foreach (array_unique($data['medication_catalog_ids']) as $catalogId) {
                ServicePrescribedMedication::create(['service_id' => $service->id, 'medication_catalog_id' => $catalogId]);
            }

            $service->visit->clinicalRecord()->firstOrCreate([])->update([
                'prescribed_medications_other' => $data['other'] ?? null,
            ]);
        });

        return response()->json([
            'medication_catalog_ids' => $service->prescribedMedications()->pluck('medication_catalog_id'),
            'other' => $data['other'] ?? null,
        ]);
    }
}
