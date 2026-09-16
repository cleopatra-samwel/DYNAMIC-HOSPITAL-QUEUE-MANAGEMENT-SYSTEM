<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9, DoD #2 — every report/summary endpoint rejects non-administrator
 * roles with 403, checked against at least 2 other roles (Doctor and
 * Registration Staff), not just asserting Administrator's own success.
 */
class ReportsRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = [
        '/api/reports/system-summary',
        '/api/reports/daily-patients',
        '/api/reports/waiting-time',
        '/api/reports/queue-performance',
        '/api/reports/emergency',
    ];

    private Department $cons;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role, 'last_name' => 'Tester', 'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'-'.uniqid().'@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public static function nonAdministratorRoles(): array
    {
        return [
            'Doctor' => ['Doctor'],
            'Registration Staff' => ['Registration Staff'],
            'Laboratory Staff' => ['Laboratory Staff'],
            'Pharmacy Staff' => ['Pharmacy Staff'],
            'Cashier/Billing Staff' => ['Cashier/Billing Staff'],
        ];
    }

    #[DataProvider('nonAdministratorRoles')]
    public function test_non_administrator_roles_are_rejected_from_every_report_endpoint(string $role): void
    {
        $user = $this->makeUser($role);

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($user)->getJson($endpoint)->assertStatus(403);
        }

        // department report needs department_id to even reach the role check's
        // sibling validation — tested with it present so 403 is unambiguous.
        $this->actingAs($user)->getJson("/api/reports/department?department_id={$this->cons->id}")->assertStatus(403);

        $this->actingAs($user)->getJson('/api/reports/daily-patients/pdf')->assertStatus(403);
    }

    public function test_administrator_can_access_every_report_endpoint(): void
    {
        $administrator = $this->makeUser('Administrator');

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($administrator)->getJson($endpoint)->assertStatus(200);
        }

        $this->actingAs($administrator)->getJson("/api/reports/department?department_id={$this->cons->id}")->assertStatus(200);
    }

    public function test_unauthenticated_requests_are_rejected_from_every_report_endpoint(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertStatus(401);
        }
    }
}
