<?php

namespace App\Enums;

/**
 * The staff roles that can log in. Patients are intentionally excluded:
 * per the requirements, patients track their visit via QR/SMS link with
 * no login, so they are never a `users` row or a Spatie role.
 */
enum StaffRole: string
{
    case Administrator = 'Administrator';
    case RegistrationStaff = 'Registration Staff';
    case Doctor = 'Doctor';
    case LaboratoryStaff = 'Laboratory Staff';
    case PharmacyStaff = 'Pharmacy Staff';
    case BillingStaff = 'Cashier/Billing Staff';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    /**
     * The dashboard route slug each role lands on after login.
     */
    public function dashboardSlug(): string
    {
        return match ($this) {
            self::Administrator => 'admin',
            self::RegistrationStaff => 'registration',
            self::Doctor => 'doctor',
            self::LaboratoryStaff => 'laboratory',
            self::PharmacyStaff => 'pharmacy',
            self::BillingStaff => 'billing',
        };
    }
}