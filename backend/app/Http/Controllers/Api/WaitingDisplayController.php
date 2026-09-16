<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QueueTicket;

/**
 * Phase 7 — backs the public waiting-area kiosk screen (no login, same as
 * TrackingController). Only queue_number + status, exactly the same
 * privacy rule the waiting-display.{departmentId} broadcast channel
 * follows — no patient name, no priority_score.
 */
class WaitingDisplayController extends Controller
{
    public function show(Department $department)
    {
        $tickets = QueueTicket::query()
            ->whereHas('service', fn ($q) => $q->where('department_id', $department->id))
            ->whereIn('status', ['CALLED', 'WAITING'])
            ->orderByRaw("CASE status WHEN 'CALLED' THEN 0 ELSE 1 END")
            ->orderByDesc('priority_score')
            ->orderBy('created_at')
            ->limit(30)
            ->get(['id', 'queue_number', 'status'])
            ->map(fn (QueueTicket $ticket) => [
                'queue_number' => $ticket->queue_number,
                'status' => $ticket->status,
            ]);

        return response()->json([
            'department_id' => $department->id,
            'department_name' => $department->dept_name,
            'tickets' => $tickets,
        ]);
    }
}
