<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Refer to Another Department" — captures the reason/notes/who/when a
 * Doctor referred a patient onward, alongside (not instead of) the
 * QueueTicket/Service pair the referral actually created (to_service_id).
 *
 * Deliberately has no stored status column — status is computed live from
 * to_service_id's QueueTicket status (see getStatusAttribute()), the same
 * way Visit.overall_status is derived rather than hand-set elsewhere in
 * this app. A stored status here would need every future ticket
 * transition to remember to keep it in sync.
 */
#[Fillable(['visit_id', 'from_service_id', 'to_service_id', 'to_department_id', 'referred_by', 'reason', 'notes'])]
class Referral extends Model
{
    // getStatusAttribute() is computed, not a column — must be explicitly
    // appended or it silently drops out of every JSON response.
    protected $appends = ['status'];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function fromService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'from_service_id');
    }

    public function toService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'to_service_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function getStatusAttribute(): string
    {
        $ticketStatus = $this->toService?->queueTicket?->status;

        return match ($ticketStatus) {
            'WAITING' => 'Pending',
            'CALLED', 'IN_SERVICE' => 'In Progress',
            'ON_HOLD' => 'On Hold',
            'COMPLETED' => 'Completed',
            'CANCELLED', 'NO_SHOW' => 'Cancelled',
            'TRANSFERRED' => 'Transferred',
            default => 'Unknown',
        };
    }
}
