<?php

use App\Models\Department;
use App\Support\DepartmentRoles;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Phase 7 — private staff channel per department. A staff member may
 * subscribe if they can normally act on that department's tickets
 * (DepartmentRoles::userCanActOn — same rule TicketTransitionService and
 * DepartmentController::callNext already enforce for the equivalent HTTP
 * actions), OR if they're Cashier/Billing Staff and the department is one
 * of the three billable ones (Consultation/Laboratory/Pharmacy) — their
 * Pending Payments list spans all three, so they need live updates from
 * each even though they don't act on tickets there directly. Administrator
 * already passes userCanActOn for every department (holds every role).
 *
 * Registration Staff may subscribe to ANY department, read-only — their
 * global Queue page (QueuePage.jsx) already shows tickets across every
 * department unscoped via GET /queue-tickets with no department filter,
 * so this just extends that existing read visibility to the live channel;
 * it grants no new action permission (DepartmentRoles::userCanActOn still
 * gates every HTTP transition, this channel authorization only decides
 * who may listen for broadcasts).
 *
 * visit.{trackingToken} and waiting-display.{departmentId} are PUBLIC
 * channels (no "private-" prefix) — Echo never calls this file for them,
 * so no entry is needed or possible here. That's deliberate: the patient
 * tracking page works with no login.
 */
Broadcast::channel('department.{departmentId}', function ($user, $departmentId) {
    $department = Department::find($departmentId);

    if (! $department) {
        return false;
    }

    if (DepartmentRoles::userCanActOn($user, $department->dept_code)) {
        return true;
    }

    if ($user->hasRole('Cashier/Billing Staff') && in_array($department->dept_code, ['CONS', 'LAB', 'PHARM'], true)) {
        return true;
    }

    return $user->hasRole('Registration Staff');
});
