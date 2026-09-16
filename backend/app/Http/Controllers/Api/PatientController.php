<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Support\PatientNumberGenerator;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /**
     * Lists patients, optionally filtered by `q` against name, patient_number,
     * or contact. Backs both GET /patients and GET /patients/search.
     */
    public function index(Request $request)
    {
        $query = Patient::query()->latest();

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('patient_number', 'like', "%{$search}%")
                    ->orWhere('contact', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    public function show(Patient $patient)
    {
        return response()->json(['patient' => $patient->load('visits')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'gender' => ['required', 'string', 'in:Male,Female,Other'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $data['patient_number'] = PatientNumberGenerator::next();

        $patient = Patient::create($data);

        return response()->json(['patient' => $patient], 201);
    }

    public function update(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'gender' => ['sometimes', 'required', 'string', 'in:Male,Female,Other'],
            'contact' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $patient->update($data);

        return response()->json(['patient' => $patient]);
    }
}
