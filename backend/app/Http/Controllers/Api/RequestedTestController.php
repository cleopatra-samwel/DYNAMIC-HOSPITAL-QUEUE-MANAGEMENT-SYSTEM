<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceRequestedTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The categorized Laboratory request checklist (Structured Laboratory
 * Request Form project) — replaces clinical_records.requested_tests free
 * text with one row per ticked catalog item (service_requested_tests),
 * plus a free-text "Others" line kept on clinical_records itself
 * (requested_tests_other), matching the paper form's layout.
 *
 * Always keyed by the REQUESTING Consultation service — see
 * Service::labRequestOriginService() for how a Laboratory service (Lab
 * Staff's read-only view, Billing's pre-selection) resolves back to it.
 */
class RequestedTestController extends Controller
{
    public function index(Request $request, Service $service)
    {
        $origin = $service->labRequestOriginService();
        $origin->loadMissing('visit.clinicalRecord');

        return response()->json([
            'lab_test_catalog_ids' => $origin->requestedTests()->pluck('lab_test_catalog_id'),
            'other' => $origin->visit->clinicalRecord?->requested_tests_other,
            // Doctor Role expansion — structured per-test results, additive
            // alongside the two keys above (kept for backward compatibility).
            'tests' => $origin->requestedTests()->with('labTest:id,name,category')->get()
                ->map(fn (ServiceRequestedTest $t) => [
                    'id' => $t->id,
                    'lab_test_catalog_id' => $t->lab_test_catalog_id,
                    'name' => $t->labTest->name,
                    'category' => $t->labTest->category,
                    'result_value' => $t->result_value,
                    'reference_range' => $t->reference_range,
                    'status' => $t->status,
                ]),
        ]);
    }

    /** Doctor-only, and only on their own Consultation service — replaces the full checklist outright, same "replace, don't append" pattern as PaymentController::verify's items. */
    public function update(Request $request, Service $service)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Doctor', 'Administrator']),
            403,
            'Only Doctor may set the Laboratory request checklist.'
        );

        $service->loadMissing('department');
        abort_unless(
            $service->department?->dept_code === 'CONS',
            422,
            'The Laboratory request checklist can only be set on a Consultation service.'
        );

        $data = $request->validate([
            'lab_test_catalog_ids' => ['present', 'array'],
            'lab_test_catalog_ids.*' => ['integer', 'exists:lab_test_catalog,id'],
            'other' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($service, $data) {
            $service->requestedTests()->delete();

            foreach (array_unique($data['lab_test_catalog_ids']) as $catalogId) {
                ServiceRequestedTest::create(['service_id' => $service->id, 'lab_test_catalog_id' => $catalogId]);
            }

            $service->visit->clinicalRecord()->firstOrCreate([])->update([
                'requested_tests_other' => $data['other'] ?? null,
            ]);
        });

        return response()->json([
            'lab_test_catalog_ids' => $service->requestedTests()->pluck('lab_test_catalog_id'),
            'other' => $data['other'] ?? null,
        ]);
    }

    /**
     * Laboratory Staff-only, and only from the LAB-side service (the
     * opposite of update() above, which is CONS-only) — records the
     * per-test result/reference-range/status Laboratory actually measured.
     * Every submitted row id is checked against labRequestOriginService()'s
     * own rows first, so one service can never overwrite another's results.
     */
    public function updateResults(Request $request, Service $service)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Laboratory Staff', 'Administrator']),
            403,
            'Only Laboratory Staff may record test results.'
        );

        $service->loadMissing('department');
        abort_unless(
            $service->department?->dept_code === 'LAB',
            422,
            'Test results can only be recorded on a Laboratory service.'
        );

        $data = $request->validate([
            'results' => ['present', 'array'],
            'results.*.id' => ['required', 'integer', 'exists:service_requested_tests,id'],
            'results.*.result_value' => ['nullable', 'string', 'max:255'],
            'results.*.reference_range' => ['nullable', 'string', 'max:255'],
            'results.*.status' => ['nullable', Rule::in(['Normal', 'Abnormal', 'Critical'])],
        ]);

        $origin = $service->labRequestOriginService();
        $validIds = $origin->requestedTests()->pluck('id');

        DB::transaction(function () use ($data, $validIds) {
            foreach ($data['results'] as $row) {
                abort_unless($validIds->contains($row['id']), 422, 'One or more results do not belong to this service\'s requested tests.');

                ServiceRequestedTest::whereKey($row['id'])->update([
                    'result_value' => $row['result_value'] ?? null,
                    'reference_range' => $row['reference_range'] ?? null,
                    'status' => $row['status'] ?? null,
                ]);
            }
        });

        return response()->json([
            'tests' => $origin->requestedTests()->with('labTest:id,name,category')->get()
                ->map(fn (ServiceRequestedTest $t) => [
                    'id' => $t->id,
                    'lab_test_catalog_id' => $t->lab_test_catalog_id,
                    'name' => $t->labTest->name,
                    'category' => $t->labTest->category,
                    'result_value' => $t->result_value,
                    'reference_range' => $t->reference_range,
                    'status' => $t->status,
                ]),
        ]);
    }
}
