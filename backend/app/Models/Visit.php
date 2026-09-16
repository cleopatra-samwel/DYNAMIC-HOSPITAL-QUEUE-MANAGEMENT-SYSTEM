<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'patient_id', 'visit_date', 'patient_type', 'emergency_confirmed',
    'payment_method', 'payment_status', 'insurance_provider', 'insurance_ref', 'overall_status',
])]
class Visit extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // tracking_token is never client-supplied (deliberately absent from
        // #[Fillable] above) — it's what the public tracking page/channel
        // use instead of the numeric id, so it must always be
        // server-generated and unguessable.
        static::creating(function (Visit $visit) {
            $visit->tracking_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'emergency_confirmed' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function insuranceCard(): HasOne
    {
        return $this->hasOne(InsuranceCard::class);
    }

    public function checkIn(): HasOne
    {
        return $this->hasOne(CheckIn::class);
    }

    public function clinicalRecord(): HasOne
    {
        return $this->hasOne(ClinicalRecord::class);
    }
}
