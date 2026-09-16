<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 7 — routes/channels.php's department.{departmentId} authorization.
 * Registration Staff may LISTEN on any department's channel (their Queue
 * page already shows every department unscoped via GET /queue-tickets, so
 * this only extends read visibility to the live channel) but this grants
 * no new HTTP action permission — DepartmentRoles::userCanActOn still
 * gates every actual transition, tested elsewhere (MultiDepartmentFlowTest
 * etc.) and unaffected by this change.
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private Department $lab;

    protected function setUp(): void
    {
        parent::setUp();

        // Testing forces BROADCAST_CONNECTION=null (phpunit.xml) so
        // TicketCalled etc. don't try to hit a real socket server — but
        // the null driver's auth() is a no-op that never even runs the
        // routes/channels.php closures (confirmed: it just returns
        // nothing for every request, authorized or not). Actually
        // exercising channel authorization needs a driver that performs
        // the real verify-then-sign flow; 'reverb' resolves to the same
        // PusherBroadcaster class Pusher uses, and its auth() signing is
        // pure HMAC computation from config — no live socket connection
        // is involved, so this is safe to force for just this test.
        config(['broadcasting.default' => 'reverb']);

        // BroadcastManager::channel() (called by routes/channels.php)
        // registers onto whatever driver was default AT BOOT TIME — which
        // was already 'null' before the override above, so that boot-time
        // registration landed on a NullBroadcaster instance the 'reverb'
        // driver created just now never inherited (BroadcastManager caches
        // one instance per driver name, lazily). Re-requiring the file
        // re-registers the same closures onto the now-current default —
        // safe since it only calls Broadcast::channel(), no declarations.
        require base_path('routes/channels.php');

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'-'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function authorize(User $user, Department $department)
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-department.{$department->id}",
        ]);
    }

    public function test_registration_staff_may_listen_on_any_department_channel(): void
    {
        $registrationStaff = $this->makeUser('Registration Staff');

        $this->authorize($registrationStaff, $this->cons)->assertStatus(200)->assertJsonStructure(['auth']);
        $this->authorize($registrationStaff, $this->lab)->assertStatus(200)->assertJsonStructure(['auth']);
    }

    public function test_pharmacy_staff_is_denied_a_department_channel_they_have_no_relation_to(): void
    {
        $pharmacyStaff = $this->makeUser('Pharmacy Staff');

        // Pharmacy Staff can't act on Laboratory tickets, isn't Billing,
        // and isn't Registration — no path grants them this channel.
        $this->authorize($pharmacyStaff, $this->lab)->assertStatus(403);
    }

    public function test_doctor_may_still_listen_on_their_own_department_channel(): void
    {
        $doctor = $this->makeUser('Doctor');

        $this->authorize($doctor, $this->cons)->assertStatus(200)->assertJsonStructure(['auth']);
    }
}
