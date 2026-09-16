<?php

namespace App\Support;

use App\Models\User;

/**
 * Which staff role is allowed to act on tickets/services in each
 * department. Shared by DepartmentController::callNext (Phase 4) and the
 * per-ticket transition actions + service flow (Phase 5), so the rule
 * lives in one place instead of being duplicated per controller.
 */
class DepartmentRoles
{
    private const ROLE_BY_DEPT_CODE = [
        'REG' => 'Registration Staff',
        'CONS' => 'Doctor',
        'LAB' => 'Laboratory Staff',
        'PHARM' => 'Pharmacy Staff',
        'BILL' => 'Cashier/Billing Staff',
    ];

    public static function roleFor(string $deptCode): ?string
    {
        return self::ROLE_BY_DEPT_CODE[$deptCode] ?? null;
    }

    public static function userCanActOn(User $user, string $deptCode): bool
    {
        $role = self::roleFor($deptCode);

        return $role !== null && $user->hasRole($role);
    }
}
