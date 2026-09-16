<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StaffUserSeeder extends Seeder
{
    /**
     * Creates one login per role, all with password "password", purely so
     * Phase 1's Definition of Done ("a user of each role can log in") is
     * checkable immediately. Remove/replace before go-live (Phase 12).
     */
    public function run(): void
    {
        foreach (StaffRole::cases() as $role) {
            $emailLocalPart = Str::slug($role->value, '.');

            $user = User::firstOrCreate(
                ['email' => "{$emailLocalPart}@hospital.test"],
                [
                    'first_name' => $role->value,
                    'last_name' => 'Demo',
                    'name' => "{$role->value} Demo",
                    'password' => 'password',
                    'is_active' => true,
                ]
            );

            if (! $user->hasRole($role->value)) {
                $user->assignRole($role->value);
            }

            // Administrator can act as every other role too — they're the
            // one account that needs to open and operate any dashboard, not
            // just their own. This grants real access at every layer (the
            // Spatie `role:` middleware, DepartmentRoles, and the frontend's
            // ProtectedRoute all key off the same role list), not just a
            // frontend-only bypass.
            if ($role === StaffRole::Administrator) {
                $user->syncRoles(StaffRole::values());
            }
        }
    }
}