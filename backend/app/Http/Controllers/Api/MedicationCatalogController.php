<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicationCatalog;
use Illuminate\Http\Request;

/** Same pattern as LabTestCatalogController — Administrator manages, Cashier/Billing Staff reads it to build an itemized Pharmacy charge, and Doctor reads it to prescribe from (PrescribedMedicationController). */
class MedicationCatalogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Administrator', 'Cashier/Billing Staff', 'Doctor']),
            403,
            'Only Administrator, Cashier/Billing Staff, or Doctor may view the medication catalog.'
        );

        return response()->json(['medications' => MedicationCatalog::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrator may manage the medication catalog.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:medication_catalog,name'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $item = MedicationCatalog::create($data + ['active' => true]);

        return response()->json(['medication' => $item], 201);
    }

    public function update(Request $request, MedicationCatalog $medicationCatalog)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrator may manage the medication catalog.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:medication_catalog,name,'.$medicationCatalog->id],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        $medicationCatalog->update($data);

        return response()->json(['medication' => $medicationCatalog->fresh()]);
    }
}
