<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'service_id', 'priority_level_id', 'queue_number', 'priority_score',
    'status', 'called_at', 'service_started_at', 'completed_at', 'call_attempts',
    'long_wait_alerted_at', 'overdue_alerted_at',
])]
class QueueTicket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'service_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'long_wait_alerted_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function priorityLevel(): BelongsTo
    {
        return $this->belongsTo(PriorityLevel::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class);
    }
}
