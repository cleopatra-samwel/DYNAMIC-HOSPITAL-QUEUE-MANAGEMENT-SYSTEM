<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Self check-in — captures only what a walk-in patient can self-report
 * before any clinical/administrative decision is made. Deliberately
 * separate from Patient/Visit: no patient_number, no department, no
 * ticket exists yet at this stage.
 */
#[Fillable(['name', 'date_of_birth', 'gender', 'contact', 'status', 'visit_id'])]
class CheckIn extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
