<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8. Patient-facing types (WAITING, APPROACHING, CALLED, TRANSFERRED,
 * COMPLETED) are scoped by visit_id — the public tracking endpoint filters
 * to exactly one visit. LONG_WAIT_ALERT is staff-facing: visit_id is null,
 * department_id/queue_ticket_id give an Administrator enough context
 * without touching patient identity.
 */
#[Fillable(['type', 'message', 'visit_id', 'queue_ticket_id', 'department_id'])]
class Notification extends Model
{
    use HasFactory;

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
