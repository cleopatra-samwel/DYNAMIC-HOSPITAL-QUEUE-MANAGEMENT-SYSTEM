<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Backs the optional "Preferred Doctor" dropdown on the registration
 * form (both the regular New Patient flow and the check-in-conversion
 * flow) — active Doctor-role users only, name+id, nothing else. Gated
 * the same way the forms that use it are (Registration Staff/
 * Administrator), not exposed to every authenticated role, even though
 * a doctor's name alone isn't especially sensitive.
 */
class DoctorController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Registration Staff', 'Administrator']),
            403,
            'Only Registration Staff may view the doctor list.'
        );

        $doctors = User::role('Doctor')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['doctors' => $doctors]);
    }
}
