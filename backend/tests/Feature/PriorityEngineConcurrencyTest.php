<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Patient;
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
 * DoD #2: two simultaneous callNext() calls on the same department must
 * never return the same ticket.
 *
 * A single PHPUnit process can't produce genuinely overlapping database
 * transactions — PHP is single-threaded, so there is no way to have one
 * transaction block mid-lock while another proceeds within one process.
 * This test instead spawns two REAL, separate OS processes (via
 * `queue:call-next-once`, both started before either is awaited), each
 * with its own DB connection to a real Postgres database, so the
 * PriorityEngine's lockForUpdate() is exercised under actual concurrent
 * execution — not simulated. The phpunit.xml testing DB (in-memory
 * SQLite, one per process) can't be used here since it isn't shared
 * across processes and doesn't support real row-level locking anyway.
 *
 * Targets the DEDICATED 'pgsql_testing' connection (see
 * config/database.php), a separate database from the real dev/prod one —
 * this used to point at the real 'pgsql' connection directly, which
 * risked leaving orphaned rows in real dev data if a run crashed mid-test
 * before tearDown() could clean up. 'pgsql_testing' reads DB_TEST_DATABASE
 * (not DB_DATABASE), which phpunit.xml never overrides, so — unlike the
 * old code this replaced — no manual re-parsing of .env is needed to
 * recover the real connection details.
 *
 * This test does not use RefreshDatabase — it cleans up its own rows in
 * tearDown() instead of relying on transaction rollback, since the
 * subprocesses need to see genuinely committed data.
 */
class PriorityEngineConcurrencyTest extends TestCase
{
    private array $createdPatientIds = [];

    private array $createdVisitIds = [];

    private array $createdServiceIds = [];

    private array $createdTicketIds = [];

    private ?Department $department = null;

    private ?User $caller = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'pgsql_testing']);
        DB::purge('pgsql_testing'); // drop any connection Laravel already opened before this config change

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Starts with "Z", not "C" — avoids colliding with the real seeded
        // "CONS" department's first letter now that Department enforces
        // first-letter uniqueness across dept_code (see Department::booted()).
        $this->department = Department::firstOrCreate(
            ['dept_code' => 'ZCONCTEST'],
            ['dept_name' => 'Concurrency Test Dept', 'is_active' => true]
        );

        PriorityLevel::firstOrCreate(['name' => 'Normal'], ['weight' => 10]);
    }

    protected function tearDown(): void
    {
        // A successful callNext() (see PriorityEngine::callNext) fires
        // TicketCalled, which NotifyOnTicketCalled turns into a real
        // Notification row (notifications.queue_ticket_id/visit_id/
        // department_id are all nullOnDelete, not cascadeOnDelete) — left
        // unhandled, every run of this test against the real Postgres dev
        // database orphaned one Notification row per ticket called
        // (confirmed by tracing the listener chain, not assumed). Deleted
        // explicitly here, matched by the tracked ticket ids so it doesn't
        // depend on the ticket row still existing.
        Notification::whereIn('queue_ticket_id', $this->createdTicketIds)->delete();

        QueueTicket::whereIn('id', $this->createdTicketIds)->delete();
        Service::whereIn('id', $this->createdServiceIds)->delete();
        Visit::whereIn('id', $this->createdVisitIds)->delete();
        Patient::whereIn('id', $this->createdPatientIds)->delete();
        $this->caller?->delete();
        $this->department?->delete();

        parent::tearDown();
    }

    private function makeWaitingTicket(): QueueTicket
    {
        $level = PriorityLevel::where('name', 'Normal')->first();

        $patient = Patient::create([
            'patient_number' => 'P-'.uniqid(),
            'name' => 'Concurrency Test Patient',
            'date_of_birth' => '1990-01-01',
            'gender' => 'Male',
            'contact' => '0000000000',
        ]);
        $this->createdPatientIds[] = $patient->id;

        $visit = Visit::create([
            'patient_id' => $patient->id,
            'visit_date' => now()->toDateString(),
            'patient_type' => 'Normal',
            'emergency_confirmed' => false,
            'payment_method' => 'Cash',
            'overall_status' => 'WAITING_CONCTEST',
        ]);
        $this->createdVisitIds[] = $visit->id;

        $service = Service::create([
            'visit_id' => $visit->id,
            'department_id' => $this->department->id,
            'service_type' => 'Concurrency Test Dept',
            'status' => 'Pending',
            'requires_payment' => true,
        ]);
        $this->createdServiceIds[] = $service->id;

        $ticket = QueueTicket::create([
            'service_id' => $service->id,
            'priority_level_id' => $level->id,
            'queue_number' => 'CT-'.uniqid(),
            'priority_score' => $level->weight,
            'status' => 'WAITING',
        ]);
        $this->createdTicketIds[] = $ticket->id;

        return $ticket;
    }

    public function test_two_concurrent_call_next_calls_never_return_the_same_ticket(): void
    {
        $ticketA = $this->makeWaitingTicket();
        $ticketB = $this->makeWaitingTicket();

        $caller = $this->caller = User::create([
            'first_name' => 'Concurrency',
            'last_name' => 'Tester',
            'name' => 'Concurrency Tester',
            'email' => 'concurrency.tester+'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        // The child process boots Laravel fresh and resolves its own config
        // from environment variables — feed it the test database's
        // connection details directly under the plain 'pgsql' name (its
        // config/database.php doesn't need to know 'pgsql_testing' exists;
        // only these raw values matter), overriding whatever phpunit.xml's
        // DB_CONNECTION=sqlite/DB_DATABASE=:memory: it would otherwise
        // inherit from this parent process's own OS environment.
        $pgsqlTesting = config('database.connections.pgsql_testing');
        $env = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $pgsqlTesting['host'],
            'DB_PORT' => (string) $pgsqlTesting['port'],
            'DB_DATABASE' => (string) $pgsqlTesting['database'],
            'DB_USERNAME' => (string) $pgsqlTesting['username'],
            'DB_PASSWORD' => (string) $pgsqlTesting['password'],
        ];

        $basePath = base_path();
        $process1 = new Process(['php', 'artisan', 'queue:call-next-once', (string) $this->department->id, (string) $caller->id], $basePath, $env);
        $process2 = new Process(['php', 'artisan', 'queue:call-next-once', (string) $this->department->id, (string) $caller->id], $basePath, $env);

        // Start both before waiting on either, so they genuinely overlap.
        $process1->start();
        $process2->start();
        $process1->wait();
        $process2->wait();

        $this->assertTrue($process1->isSuccessful(), $process1->getErrorOutput());
        $this->assertTrue($process2->isSuccessful(), $process2->getErrorOutput());

        $result1 = trim($process1->getOutput());
        $result2 = trim($process2->getOutput());

        $this->assertContains($result1, [(string) $ticketA->id, (string) $ticketB->id]);
        $this->assertContains($result2, [(string) $ticketA->id, (string) $ticketB->id]);
        $this->assertNotSame($result1, $result2, 'Both concurrent callNext() calls returned the same ticket.');

        // Both tickets must now be CALLED exactly once — no ticket left
        // WAITING, none double-processed.
        $this->assertSame(
            0,
            DB::connection('pgsql_testing')->table('queue_tickets')
                ->whereIn('id', [$ticketA->id, $ticketB->id])
                ->where('status', 'WAITING')
                ->count()
        );
    }
}
