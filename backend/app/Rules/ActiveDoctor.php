<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shared by VisitController::store and CheckInController::convert — the
 * frontend dropdown only ever lists active Doctor-role users, but this is
 * the server-side guarantee: doctor_id must genuinely belong to an active
 * account actually holding the Doctor role (checked via reallyHasRole,
 * the unscoped Spatie grant — not the active-session-scoped hasRole(),
 * since this is asking "is this user a Doctor at all", not "is Doctor
 * their currently active role").
 */
class ActiveDoctor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::find($value);

        if (! $user || ! $user->is_active || ! $user->reallyHasRole('Doctor')) {
            $fail('The selected doctor is not a valid, active Doctor account.');
        }
    }
}
