<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Multi-role fix — a user can be GRANTED several roles (Spatie's normal
 * model_has_roles, unchanged) but only one is ACTIVE per session/device
 * at a time (carried on the Sanctum token itself, see User::hasRole()'s
 * override). This is what makes "switch role in the dropdown, and API
 * calls afterward are restricted to the newly active role" genuinely
 * true, not just a cosmetic UI change.
 *
 * Despite the spec describing this as adding a new user_roles pivot
 * table, no such table was needed or created — confirmed live that this
 * app has no users.role column at all; it already used Spatie's full
 * many-to-many role system everywhere (assignRole/hasRole across dozens
 * of files). Building a second, parallel role-storage table alongside
 * Spatie's existing one would have created two driftable sources of
 * truth for "which roles does this user have" — so this reuses Spatie's
 * existing grants directly and only adds the missing piece: which one
 * is active right now.
 */
class RoleSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private Department $lab;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
    }

    private function makeUser(string $email, array $roles): User
    {
        $user = User::create([
            'first_name' => 'Test', 'last_name' => 'User', 'name' => 'Test User',
            'email' => $email, 'password' => 'password', 'is_active' => true,
        ]);
        $user->syncRoles($roles);

        return $user;
    }

    public function test_login_response_lists_all_granted_roles_and_defaults_active_role_sensibly(): void
    {
        $multiRole = $this->makeUser('multi@test.local', ['Registration Staff', 'Cashier/Billing Staff']);

        $response = $this->postJson('/api/auth/login', ['email' => $multiRole->email, 'password' => 'password'])
            ->assertStatus(200);

        $roles = $response->json('user.roles');
        $this->assertContains('Registration Staff', $roles);
        $this->assertContains('Cashier/Billing Staff', $roles);
        $this->assertCount(2, $roles);
        // No Administrator granted — defaults to whichever role was assigned first.
        $this->assertSame('Registration Staff', $response->json('user.active_role'));

        $administrator = $this->makeUser('admin-multi@test.local', ['Doctor', 'Administrator']);
        $adminResponse = $this->postJson('/api/auth/login', ['email' => $administrator->email, 'password' => 'password'])->assertStatus(200);
        // Administrator is always preferred as the default, regardless of grant order.
        $this->assertSame('Administrator', $adminResponse->json('user.active_role'));
    }

    public function test_switching_active_role_is_rejected_for_a_role_not_granted_to_the_user(): void
    {
        $user = $this->makeUser('single@test.local', ['Registration Staff']);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/auth/active-role', ['role' => 'Doctor'])
            ->assertStatus(403);
    }

    /**
     * The actual DoD scenario, end to end with a REAL Sanctum token (not
     * Sanctum::actingAs()'s mock) so the real login -> act -> switch ->
     * act flow is exercised exactly as a browser would: a Registration
     * Staff + Cashier/Billing Staff user can do Registration-only things
     * while that's active, gets rejected from Billing-only things, then
     * after switching the reverse is true.
     */
    public function test_switching_active_role_via_the_real_endpoint_changes_what_subsequent_api_calls_may_do(): void
    {
        $user = $this->makeUser('dual@test.local', ['Registration Staff', 'Cashier/Billing Staff']);
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Dual Role Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000400']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_LAB']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);

        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('token');
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // Default active role is Registration Staff (assigned first) —
        // Registration-only action succeeds, Billing-only action doesn't.
        $auth()->postJson("/api/visits/{$visit->id}/insurance-card")->assertStatus(201);

        $secondPatient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Second Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000401']);
        $secondVisit = Visit::create(['patient_id' => $secondPatient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_LAB']);
        $secondService = Service::create(['visit_id' => $secondVisit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);

        $auth()->postJson("/api/services/{$secondService->id}/payments/verify", ['method' => 'Cash', 'items' => [['catalog_type' => 'other', 'custom_label' => 'Fee', 'amount' => 1000]]])
            ->assertStatus(403);

        // Switch active role to Cashier/Billing Staff via the real endpoint.
        $auth()->patchJson('/api/auth/active-role', ['role' => 'Cashier/Billing Staff'])
            ->assertStatus(200)
            ->assertJsonPath('user.active_role', 'Cashier/Billing Staff');

        // Now the reverse is true: Billing-only succeeds, Registration-only is rejected.
        $auth()->postJson("/api/services/{$secondService->id}/payments/verify", ['method' => 'Cash', 'items' => [['catalog_type' => 'other', 'custom_label' => 'Fee', 'amount' => 1000]]])
            ->assertStatus(201);

        $thirdPatient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Third Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000402']);
        $thirdVisit = Visit::create(['patient_id' => $thirdPatient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_LAB']);

        $auth()->postJson("/api/visits/{$thirdVisit->id}/insurance-card")->assertStatus(403);
    }

    public function test_a_role_granted_but_not_currently_active_still_fails_role_middleware_checks(): void
    {
        $user = $this->makeUser('middleware-test@test.local', ['Doctor', 'Laboratory Staff']);

        // Sanctum::actingAs simulates a real token with exactly ['Doctor']
        // as its ability — Laboratory Staff is genuinely granted but not
        // active for this simulated session.
        Sanctum::actingAs($user, ['Doctor']);

        $this->getJson('/api/laboratory/ping')->assertStatus(403);
        $this->getJson('/api/doctor/ping')->assertStatus(200);
    }

    public function test_no_token_context_falls_back_to_unrestricted_spatie_behavior(): void
    {
        // Plain actingAs() (no Sanctum token at all) — every pre-existing
        // test in this app's suite uses exactly this pattern, which is
        // why none of them needed to change for this fix to land.
        $user = $this->makeUser('plain-actingas@test.local', ['Doctor']);

        $this->actingAs($user)->getJson('/api/doctor/ping')->assertStatus(200);
    }
}
