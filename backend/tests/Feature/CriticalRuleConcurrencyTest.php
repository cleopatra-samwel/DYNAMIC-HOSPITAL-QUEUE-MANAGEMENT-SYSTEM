<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Critical Rule ("a visit can never have two tickets IN_SERVICE at
 * once") was previously only proven with a SEQUENTIAL test — ticket A was
 * already committed IN_SERVICE before the single request for ticket B was
 * made. That proves the check's logic, not that lockForUpdate() actually
 * serializes two requests racing each other before either commits.
 *
 * Same technique as PriorityEngineConcurrencyTest: a single PHPUnit
 * process can't produce genuinely overlapping transactions (PHP is
 * single-threaded), so this spawns two real, separate OS processes (via
 * `queue:start-service-once`, both started before either is awaited),
 * each with its own connection to the real Postgres database, attempting
 * to move two DIFFERENT tickets of the SAME visit to IN_SERVICE at the
 * same time. Exactly one must win.
 *
 * Uses the real 'pgsql' connection (not RefreshDatabase/sqlite) since the
 * subprocesses need to see genuinely committed data; cleans up its own
 * rows in tearDown() instead of relying on transaction rollback.
 */
class CriticalRuleConcurrencyTest extends TestCase
{
    private array $createdPatientIds = [];

    private array $createdVisitIds = [];

    private array $createdServiceIds = [];

    private array $createdTicketIds = [];

    private array $createdDepartmentIds = [];

    private ?User $caller = null;

    private function realEnvValue(string $key, string $default = ''): string
    {
        static $lines = null;
        $lines ??= file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return substr($line, strlen($key) + 1);
            }
        }

        return $default;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $this->realEnvValue('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => $this->realEnvValue('DB_PORT', '5432'),
            'database.connections.pgsql.database' => $this->realEnvValue('DB_DATABASE'),
            'database.connections.pgsql.username' => $this->realEnvValue('DB_USERNAME'),
            'database.connections.pgsql.password' => $this->realEnvValue('DB_PASSWORD'),
        ]);
        DB::purge('pgsql');

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::firstOrCreate(['name' => 'Normal'], ['weight' => 10]);
    }

    protected function tearDown(): void
    {
        QueueTicket::whereIn('id', $this->createdTicketIds)->delete();
        Service::whereIn('id', $this->createdServiceIds)->delete();
        Visit::whereIn('id', $this->createdVisitIds)->delete();
        Patient::whereIn('id', $this->createdPatientIds)->delete();
        $this->caller?->delete();
        Department::whereIn('id', $this->createdDepartmentIds)->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_start_service_calls_for_the_same_visit_never_both_succeed(): void
    {
        // DepartmentRoles only recognizes real dept_codes (REG/CONS/LAB/
        // PHARM/BILL) — a made-up code would fail the role check for
        // every user, including Administrator, which isn't what this test
        // is exercising. Reuse two real ones instead of inventing new
        // departments (nothing here is deleted afterward, unlike the
        // patient/visit/service/ticket rows this test does clean up).
        $deptA = Department::firstOrCreate(['dept_code' => 'CONS'], ['dept_name' => 'Consultation', 'is_active' => true]);
        $deptB = Department::firstOrCreate(['dept_code' => 'LAB'], ['dept_name' => 'Laboratory', 'is_active' => true]);

        $level = PriorityLevel::where('name', 'Normal')->first();

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Critical Rule Race Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0000000001']);
        $this->createdPatientIds[] = $patient->id;

        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CRTESTA']);
        $this->createdVisitIds[] = $visit->id;

        // Same visit, two DIFFERENT departments — exactly the scenario the
        // Critical Rule exists to prevent.
        $serviceA = Service::create(['visit_id' => $visit->id, 'department_id' => $deptA->id, 'service_type' => 'A', 'status' => 'Pending', 'requires_payment' => true]);
        $serviceB = Service::create(['visit_id' => $visit->id, 'department_id' => $deptB->id, 'service_type' => 'B', 'status' => 'Pending', 'requires_payment' => true]);
        $this->createdServiceIds = [$serviceA->id, $serviceB->id];

        $ticketA = QueueTicket::create(['service_id' => $serviceA->id, 'priority_level_id' => $level->id, 'queue_number' => 'CRA-'.uniqid(), 'priority_score' => 10, 'status' => 'CALLED']);
        $ticketB = QueueTicket::create(['service_id' => $serviceB->id, 'priority_level_id' => $level->id, 'queue_number' => 'CRB-'.uniqid(), 'priority_score' => 10, 'status' => 'CALLED']);
        $this->createdTicketIds = [$ticketA->id, $ticketB->id];

        // Phase 6: both departments are now billable — this test is
        // specifically exercising the Critical Rule race, not the payment
        // gate, so pre-verify payment for both (cascades away with the
        // Service rows in tearDown, no separate cleanup needed).
        Payment::create(['service_id' => $serviceA->id, 'method' => 'Cash', 'amount' => 1000, 'status' => 'VERIFIED', 'verified_at' => now()]);
        Payment::create(['service_id' => $serviceB->id, 'method' => 'Cash', 'amount' => 1000, 'status' => 'VERIFIED', 'verified_at' => now()]);

        // Administrator holds every department's role, so one account can
        // legitimately act on both tickets.
        $caller = $this->caller = User::create([
            'first_name' => 'Critical',
            'last_name' => 'Tester',
            'name' => 'Critical Rule Tester',
            'email' => 'critical.rule.tester+'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $caller->assignRole(StaffRole::values());

        $pgsql = config('database.connections.pgsql');
        $env = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $pgsql['host'],
            'DB_PORT' => (string) $pgsql['port'],
            'DB_DATABASE' => (string) $pgsql['database'],
            'DB_USERNAME' => (string) $pgsql['username'],
            'DB_PASSWORD' => (string) $pgsql['password'],
        ];

        $basePath = base_path();
        $process1 = new Process(['php', 'artisan', 'queue:start-service-once', (string) $ticketA->id, (string) $caller->id], $basePath, $env);
        $process2 = new Process(['php', 'artisan', 'queue:start-service-once', (string) $ticketB->id, (string) $caller->id], $basePath, $env);

        // Start both before waiting on either, so they genuinely overlap.
        $process1->start();
        $process2->start();
        $process1->wait();
        $process2->wait();

        $this->assertTrue($process1->isSuccessful(), $process1->getErrorOutput());
        $this->assertTrue($process2->isSuccessful(), $process2->getErrorOutput());

        $result1 = trim($process1->getOutput());
        $result2 = trim($process2->getOutput());

        $outcomes = [$result1, $result2];
        sort($outcomes);
        $this->assertSame(['OK', 'REJECTED:409'], $outcomes, "Expected exactly one OK and one REJECTED:409, got: {$result1} / {$result2}");

        // Exactly one of the two tickets is IN_SERVICE — never both, never neither.
        $inServiceCount = DB::connection('pgsql')->table('queue_tickets')
            ->whereIn('id', [$ticketA->id, $ticketB->id])
            ->where('status', 'IN_SERVICE')
            ->count();
        $this->assertSame(1, $inServiceCount, 'Exactly one ticket must have won the race to IN_SERVICE.');

        $stillCalledCount = DB::connection('pgsql')->table('queue_tickets')
            ->whereIn('id', [$ticketA->id, $ticketB->id])
            ->where('status', 'CALLED')
            ->count();
        $this->assertSame(1, $stillCalledCount, 'The loser must remain CALLED, untouched.');
    }
}
