<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Phase 8 — currently only serves LONG_WAIT_ALERT (Administrator-only, per
 * the spec: "administrator only for now" since there's no Supervisor
 * concept in StaffRole yet). Patient-facing notification types are never
 * exposed here — those are visit-scoped and only reachable via the public
 * tracking endpoint (TrackingController), which already filters to one
 * visit at a time.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:LONG_WAIT_ALERT'],
        ]);

        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may view alerts.');

        $notifications = Notification::where('type', $data['type'])
            ->with(['queueTicket.priorityLevel', 'department'])
            ->latest()
            ->get();

        return response()->json(['notifications' => $notifications]);
    }
}
