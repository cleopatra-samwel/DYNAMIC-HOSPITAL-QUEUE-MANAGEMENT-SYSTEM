<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequestedTest;
use Illuminate\Http\Request;

/**
 * "Laboratory Results" — a flat, all-patients reference log of every
 * recorded structured result, distinct from the per-visit results already
 * visible inside TicketViewModal/Patient Records and from the Laboratory
 * Queue (which lists tickets, not results). Doctor + Administrator only —
 * mirrors the read-access level of other cross-visit clinical views.
 */
class LabResultsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Doctor', 'Administrator']),
            403,
            'Only Doctor may view the Laboratory Results log.'
        );

        $query = ServiceRequestedTest::query()
            ->with(['labTest:id,name,category', 'service.visit.patient', 'service.department'])
            ->where(fn ($q) => $q->whereNotNull('result_value')->orWhereNotNull('status'))
            ->latest('updated_at');

        if ($search = $request->query('q')) {
            $query->whereHas('service.visit.patient', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('patient_number', 'like', "%{$search}%");
            });
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('updated_at', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('updated_at', '<=', $to);
        }

        return response()->json($query->paginate(20));
    }
}
