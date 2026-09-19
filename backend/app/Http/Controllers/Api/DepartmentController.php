<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QueueTicket;
use App\Models\Referral;
use App\Models\ServiceRequestedTest;
use App\Models\Visit;
use App\Services\PriorityEngine;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        return response()->json([
            'departments' => Department::where('is_active', true)->orderBy('dept_name')->get(),
        ]);
    }

    public function callNext(Request $request, Department $department, PriorityEngine $engine)
    {
        abort_unless(
            DepartmentRoles::userCanActOn($request->user(), $department->dept_code),
            403,
            'You are not permitted to call patients for this department.'
        );

        $ticket = $engine->callNext($department->id, $request->user()->id);

        if (! $ticket) {
            return response()->json(['ticket' => null, 'message' => 'No patients waiting in this department.']);
        }

        return response()->json(['ticket' => $ticket]);
    }

    /**
     * Backs each department dashboard's summary cards. Every dashboard uses
     * a different subset of these fields under its own labels (Doctor:
     * waiting/from_registration/lab_results_ready/in_service/
     * pending_referrals/emergency_unconfirmed/completed_today; Laboratory:
     * waiting/called/pending_tests/in_service/completed_today/
     * results_sent_to_doctors; Pharmacy: waiting/completed_today/
     * pending_payment) — the shape is a superset so one endpoint serves all
     * three rather than three near-duplicate ones. Every count is a live
     * query, never derived/cached client-side.
     */
    public function stats(Request $request, Department $department)
    {
        abort_unless(
            DepartmentRoles::userCanActOn($request->user(), $department->dept_code),
            403,
            'You are not permitted to view stats for this department.'
        );

        $inDept = fn () => QueueTicket::query()->whereHas('service', fn ($q) => $q->where('department_id', $department->id));

        return response()->json([
            'waiting' => $inDept()->where('status', 'WAITING')->count(),
            'in_service' => $inDept()->where('status', 'IN_SERVICE')->count(),
            'completed_today' => $inDept()->where('status', 'COMPLETED')->whereDate('completed_at', now()->toDateString())->count(),
            'urgent' => $inDept()
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_SERVICE', 'ON_HOLD'])
                ->whereHas('service.visit', fn ($q) => $q->where('patient_type', 'Emergency'))
                ->count(),
            // Global, not department-scoped: any Emergency visit not yet
            // clinically confirmed by a Doctor — same number everywhere.
            'emergency_unconfirmed' => Visit::where('patient_type', 'Emergency')->where('emergency_confirmed', false)->count(),
            // Active/waiting tickets in this department whose visit's
            // payment is still Pending (display-only here — Cashier/Billing
            // Staff verify payment in a later phase).
            'pending_payment' => $inDept()
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_SERVICE', 'ON_HOLD'])
                ->whereHas('service.visit', fn ($q) => $q->where('payment_status', 'Pending'))
                ->count(),
            // Doctor Role expansion — WAITING tickets fresh from Registration
            // (either self-check-in's REG hop, or a visit that started
            // directly in this department with no previous service at all —
            // VisitController::store's department_id isn't always REG).
            'from_registration' => $inDept()->where('status', 'WAITING')
                ->where(fn ($q) => $q
                    ->whereHas('service', fn ($s) => $s->whereNull('previous_service_id'))
                    ->orWhereHas('service.previousService.department', fn ($d) => $d->where('dept_code', 'REG')))
                ->count(),
            // WAITING tickets whose previous stop was Laboratory — i.e.
            // patients returning with results ready for review.
            'lab_results_ready' => $inDept()->where('status', 'WAITING')
                ->whereHas('service.previousService.department', fn ($d) => $d->where('dept_code', 'LAB'))
                ->count(),
            // This doctor's own referrals whose destination ticket hasn't
            // been called yet — user-scoped like emergency_unconfirmed is
            // department-agnostic, not $department-scoped.
            'pending_referrals' => Referral::where('referred_by', $request->user()->id)
                ->whereHas('toService.queueTicket', fn ($q) => $q->where('status', 'WAITING'))
                ->count(),
            // Laboratory Role expansion.
            'called' => $inDept()->where('status', 'CALLED')->count(),
            // Requested tests still missing a result, for patients currently
            // active in this department's own queue (not all-time history).
            'pending_tests' => ServiceRequestedTest::whereNull('result_value')->whereNull('status')
                ->whereHas('service.nextService', fn ($s) => $s->where('department_id', $department->id)
                    ->whereHas('queueTicket', fn ($q) => $q->whereIn('status', ['WAITING', 'CALLED', 'IN_SERVICE'])))
                ->count(),
            // Completed today AND forwarded specifically to Consultation
            // (Laboratory's other forward target, Pharmacy, isn't "sent to
            // a doctor").
            'results_sent_to_doctors' => $inDept()->where('status', 'COMPLETED')
                ->whereDate('completed_at', now()->toDateString())
                ->whereHas('service.nextService.department', fn ($d) => $d->where('dept_code', 'CONS'))
                ->count(),
        ]);
    }
}
