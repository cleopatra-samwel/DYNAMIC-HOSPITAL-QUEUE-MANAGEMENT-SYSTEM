<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['dept_code', 'dept_name', 'description', 'counter_number', 'location_phrase_type', 'append_doctor_phrase', 'is_active'])]
class Department extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // AnnouncementController speaks only the FIRST character of
        // dept_code to identify a department by voice (queue_number's
        // multi-letter prefix, e.g. "C" for "CONS-0024") — a future
        // department sharing that first letter with an existing one
        // (e.g. "Cardiology" alongside "Consultation") would make two
        // departments indistinguishable by ear without anyone noticing
        // until a patient is announced to the wrong counter. Enforced
        // here at the model layer (not a FormRequest) so it holds no
        // matter how a department gets created — seeder, tinker, or a
        // future admin UI/API alike — since no such endpoint exists yet.
        static::saving(function (Department $department) {
            if (! $department->dept_code) {
                return;
            }

            $firstLetter = strtoupper($department->dept_code[0]);

            $conflict = static::where('id', '!=', $department->id ?? 0)
                ->get(['id', 'dept_code'])
                ->first(fn (Department $other) => strtoupper($other->dept_code[0]) === $firstLetter);

            if ($conflict) {
                throw ValidationException::withMessages([
                    'dept_code' => ["Department code \"{$department->dept_code}\" starts with the same letter as existing department \"{$conflict->dept_code}\" — voice announcements identify a department by only its first letter, so every department code must start with a distinct letter."],
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'append_doctor_phrase' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** REG is never a referral target (it's the entry point, not a destination — see ServiceFlowController::store), and referring a department to itself makes no sense. */
    public static function isReferrable(string $deptCode, string $fromDeptCode): bool
    {
        return $deptCode !== 'REG' && $deptCode !== $fromDeptCode;
    }
}