<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Administrator's "Users & Roles" page — grant/revoke roles per user.
 * This is what populates the multi-role dropdown's options for each user
 * (see RoleSwitchingTest for the switching side of this feature).
 */
class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $email, array $roles = []): User
    {
        $user = User::create([
            'first_name' => 'Test', 'last_name' => 'User', 'name' => 'Test User',
            'email' => $email, 'password' => 'password', 'is_active' => true,
        ]);
        if ($roles) {
            $user->syncRoles($roles);
        }

        return $user;
    }

    public function test_administrator_can_grant_a_second_role_to_a_user(): void
    {
        $administrator = $this->makeUser('admin@test.local', ['Administrator']);
        $target = $this->makeUser('target@test.local', ['Registration Staff']);

        $response = $this->actingAs($administrator)->patchJson("/api/users/{$target->id}/roles", [
            'roles' => ['Registration Staff', 'Cashier/Billing Staff'],
        ])->assertStatus(200);

        $roles = $response->json('user.roles');
        $this->assertContains('Registration Staff', $roles);
        $this->assertContains('Cashier/Billing Staff', $roles);
        $this->assertCount(2, $roles);

        $this->assertTrue($target->fresh()->hasRole('Registration Staff'));
        $this->assertTrue($target->fresh()->hasRole('Cashier/Billing Staff'));
    }

    public function test_administrator_can_revoke_a_role_by_omitting_it(): void
    {
        $administrator = $this->makeUser('admin2@test.local', ['Administrator']);
        $target = $this->makeUser('target2@test.local', ['Registration Staff', 'Cashier/Billing Staff']);

        $this->actingAs($administrator)->patchJson("/api/users/{$target->id}/roles", [
            'roles' => ['Registration Staff'],
        ])->assertStatus(200);

        $this->assertTrue($target->fresh()->hasRole('Registration Staff'));
        $this->assertFalse($target->fresh()->hasRole('Cashier/Billing Staff'));
    }

    public function test_non_administrator_cannot_manage_user_roles(): void
    {
        $doctor = $this->makeUser('doctor@test.local', ['Doctor']);
        $target = $this->makeUser('target3@test.local', ['Registration Staff']);

        $this->actingAs($doctor)->getJson('/api/users')->assertStatus(403);
        $this->actingAs($doctor)->patchJson("/api/users/{$target->id}/roles", ['roles' => ['Doctor']])->assertStatus(403);
    }

    public function test_at_least_one_role_is_required(): void
    {
        $administrator = $this->makeUser('admin3@test.local', ['Administrator']);
        $target = $this->makeUser('target4@test.local', ['Registration Staff']);

        $this->actingAs($administrator)->patchJson("/api/users/{$target->id}/roles", ['roles' => []])
            ->assertStatus(422);

        $this->assertTrue($target->fresh()->hasRole('Registration Staff'), 'The rejected request must not have changed anything.');
    }

    public function test_an_unknown_role_name_is_rejected(): void
    {
        $administrator = $this->makeUser('admin4@test.local', ['Administrator']);
        $target = $this->makeUser('target5@test.local', ['Registration Staff']);

        $this->actingAs($administrator)->patchJson("/api/users/{$target->id}/roles", ['roles' => ['Not A Real Role']])
            ->assertStatus(422);
    }

    public function test_index_lists_every_user_with_their_roles_and_the_available_role_list(): void
    {
        $administrator = $this->makeUser('admin5@test.local', ['Administrator']);
        $this->makeUser('target6@test.local', ['Doctor']);

        $response = $this->actingAs($administrator)->getJson('/api/users')->assertStatus(200);

        $emails = collect($response->json('users'))->pluck('email');
        $this->assertTrue($emails->contains('admin5@test.local'));
        $this->assertTrue($emails->contains('target6@test.local'));
        $this->assertSame(StaffRole::values(), $response->json('available_roles'));
    }
}
