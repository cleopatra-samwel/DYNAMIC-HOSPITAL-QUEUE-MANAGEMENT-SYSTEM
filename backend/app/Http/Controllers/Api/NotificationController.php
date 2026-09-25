<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Notification;
use App\Support\DepartmentRoles;
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

    /**
     * Backs the alert bell in the dashboard header: WAIT_OVERDUE alerts for
     * the departments THIS user can act on (same rule as ticket actions),
     * and only while the ticket is still WAITING — once the patient is
     * called the alert drops off by itself. `include_resolved=1` (used by
     * the Notification page) keeps alerts for patients who have since been
     * called, so it doubles as a history.
     */
    public function staffAlerts(Request $request)
    {
        $user = $request->user();

        $departmentIds = Department::all()
            ->filter(fn (Department $d) => DepartmentRoles::userCanActOn($user, $d->dept_code))
            ->pluck('id');

        $alerts = Notification::where('type', 'WAIT_OVERDUE')
            ->whereIn('department_id', $departmentIds)
            ->when(! $request->boolean('include_resolved'), fn ($q) => $q->whereHas('queueTicket', fn ($t) => $t->where('status', 'WAITING')))
            ->with(['queueTicket:id,queue_number,status,created_at', 'department:id,dept_name'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['alerts' => $alerts]);
    }
}
