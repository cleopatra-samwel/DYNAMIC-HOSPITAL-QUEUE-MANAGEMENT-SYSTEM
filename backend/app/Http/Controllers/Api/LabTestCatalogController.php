<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabTestCatalog;
use Illuminate\Http\Request;

/**
 * Administrator manages this list. Everyone else only ever reads it, for
 * one of three purposes: Cashier/Billing Staff builds an itemized
 * Laboratory charge (see PaymentController::verify), Doctor renders the
 * categorized checklist on the Request Laboratory form, and Laboratory
 * Staff renders that same checklist read-only — one index() shared by
 * all of them rather than near-duplicate endpoints, same reasoning as
 * DepartmentController::stats's "one endpoint, several consumers" pattern.
 */
class LabTestCatalogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Administrator', 'Cashier/Billing Staff', 'Doctor', 'Laboratory Staff']),
            403,
            'You may not view the lab test catalog.'
        );

        return response()->json(['lab_tests' => LabTestCatalog::orderBy('category')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrator may manage the lab test catalog.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:lab_test_catalog,name'],
            'category' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $item = LabTestCatalog::create($data + ['active' => true]);

        return response()->json(['lab_test' => $item], 201);
    }

    public function update(Request $request, LabTestCatalog $labTestCatalog)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrator may manage the lab test catalog.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:lab_test_catalog,name,'.$labTestCatalog->id],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        $labTestCatalog->update($data);

        return response()->json(['lab_test' => $labTestCatalog->fresh()]);
    }
}
