<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\Request;

/**
 * Read-only — referrals are created inside ServiceFlowController::store
 * (atomically with the Service/QueueTicket the referral produced), never
 * via a standalone POST here. See Referral::getStatusAttribute() for how
 * "status" is computed live from the linked ticket, not stored.
 */
class ReferralController extends Controller
{
    // Deliberately does NOT eager-load referredBy: Eloquent serializes
    // relation names snake_cased, and "referredBy" -> "referred_by"
    // collides with the actual referred_by FK column already on the
    // model — array_merge(attributes, relations) would silently replace
    // the plain integer with the loaded User object in the JSON response.
    // referred_by is already a plain id in every response as a result.
    private const RELATIONS = ['visit.patient', 'toDepartment', 'toService.queueTicket'];

    /** Doctor (non-Administrator) is always server-scoped to their own referrals — never client-toggleable, same pattern as lab_technician_id being server-set only. */
    public function index(Request $request)
    {
        $query = Referral::query()->with(self::RELATIONS)->latest();

        if (! $request->user()->hasRole('Administrator')) {
            $query->where('referred_by', $request->user()->id);
        }

        if ($departmentId = $request->query('department_id')) {
            $query->where('to_department_id', $departmentId);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Request $request, Referral $referral)
    {
        abort_unless(
            $request->user()->hasRole('Administrator') || $referral->referred_by === $request->user()->id,
            403,
            'You are not permitted to view this referral.'
        );

        return response()->json(['referral' => $referral->load(self::RELATIONS)]);
    }
}
