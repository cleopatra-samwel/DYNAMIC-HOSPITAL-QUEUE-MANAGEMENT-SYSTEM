<?php

namespace App\Support;

use App\Models\Department;
use App\Models\QueueTicket;

/**
 * Shared by VisitController (first ticket of a visit) and
 * ServiceFlowController (every subsequent department transfer), so ticket
 * numbering stays consistent everywhere a ticket is created.
 */
class QueueNumberGenerator
{
    public static function next(Department $department): string
    {
        $countToday = QueueTicket::whereHas(
            'service',
            fn ($q) => $q->where('department_id', $department->id)
        )->whereDate('created_at', now()->toDateString())->count();

        $sequence = str_pad((string) ($countToday + 1), 4, '0', STR_PAD_LEFT);

        return "{$department->dept_code}-{$sequence}";
    }
}
