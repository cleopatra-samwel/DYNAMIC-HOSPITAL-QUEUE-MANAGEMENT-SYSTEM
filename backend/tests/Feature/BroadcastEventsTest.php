<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Events\PaymentVerified;
use App\Events\PriorityRecalculated;
use App\Events\TicketCalled;
use App\Events\TicketStatusChanged;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\QueueTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 7 — DoD #3: no patient name, contact, or payment/insurance detail
 * ever appears in a broadcast payload sent to a public channel. Rather
 * than trust that by inspection, this asserts it directly against the
 * actual broadcastWith()/broadcastOn() output of each event, and pins
 * which channels are public (Channel) vs private (PrivateChannel) so a
 * future change can't accidentally widen what a public channel receives.
 */
class BroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_KEYS = [
        'patient_name', 'name', 'contact', 'patient', 'amount',
        'insurance_provider', 'insurance_ref', 'insurance',
    ];

    private Department $cons;

    private Department $lab;

    private User $doctor;

    private User $billingStaff;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        $this->doctor = $this->makeUser('Doctor');
        $this->billingStaff = $this->makeUser('Cashier/Billing Staff');
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

    /** A ticket with requires_payment=false, so the Payment Gate never enters the picture — this test is about broadcast shape, not the gate. */
    private function makeWaitingTicket(Department $department): QueueTicket
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Broadcast Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000099']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => "WAITING_{$department->dept_code}"]);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => $department->dept_name, 'status' => 'Pending', 'requires_payment' => false]);
        $normal = PriorityLevel::where('name', 'Normal')->first();

        return QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => "{$department->dept_code}-TEST", 'priority_score' => 10, 'status' => 'WAITING']);
    }

    private function assertPayloadHasNoForbiddenKeys(array $payload): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "Broadcast payload must never carry '{$key}'.");
        }
    }

    /** @return array{0: array<Channel>, 1: array<PrivateChannel>} [publicChannels, privateChannels], split by exact class (PrivateChannel extends Channel, so this must check exact class, not instanceof). */
    private function splitChannels(array $channels): array
    {
        $public = array_values(array_filter($channels, fn ($c) => $c::class === Channel::class));
        $private = array_values(array_filter($channels, fn ($c) => $c instanceof PrivateChannel));

        return [$public, $private];
    }

    public function test_ticket_called_broadcasts_privacy_safe_payload_to_both_public_and_private_channels(): void
    {
        Event::fake([TicketCalled::class]);

        $this->makeWaitingTicket($this->cons);

        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertStatus(200);

        Event::assertDispatched(TicketCalled::class, function (TicketCalled $event) {
            $payload = $event->broadcastWith();
            $this->assertPayloadHasNoForbiddenKeys($payload);
            $this->assertArrayNotHasKey('priority_score', $payload, 'priority_score must never appear on TicketCalled — it fans out to public channels.');
            $this->assertSame(['ticket_id', 'queue_number', 'status', 'department_id'], array_keys($payload));
            $this->assertSame('CALLED', $payload['status']);

            [$public, $private] = $this->splitChannels($event->broadcastOn());
            $this->assertCount(2, $public, 'Expected waiting-display and visit public channels.');
            $this->assertCount(1, $private, 'Expected exactly one private department channel.');
            $this->assertSame("private-department.{$this->cons->id}", $private[0]->name);
            $publicNames = collect($public)->map(fn ($c) => $c->name)->sort()->values()->all();
            $this->assertTrue(str_starts_with($publicNames[0], 'visit.'), 'One public channel must be visit.{trackingToken}.');
            $this->assertSame("waiting-display.{$this->cons->id}", $publicNames[1]);

            return true;
        });
    }

    public function test_ticket_status_changed_broadcasts_privacy_safe_payload_when_started_and_completed(): void
    {
        Event::fake([TicketStatusChanged::class]);

        $ticket = $this->makeWaitingTicket($this->cons);
        $ticket->update(['status' => 'CALLED', 'called_at' => now()]);

        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/start-service")->assertStatus(200);

        Event::assertDispatched(TicketStatusChanged::class, function (TicketStatusChanged $event) {
            $payload = $event->broadcastWith();
            $this->assertPayloadHasNoForbiddenKeys($payload);
            $this->assertArrayNotHasKey('priority_score', $payload);
            $this->assertSame(['ticket_id', 'queue_number', 'status', 'department_id'], array_keys($payload));
            $this->assertSame('IN_SERVICE', $payload['status']);

            [$public, $private] = $this->splitChannels($event->broadcastOn());
            $this->assertCount(2, $public);
            $this->assertCount(1, $private);

            return true;
        });
    }

    public function test_priority_recalculated_broadcasts_only_to_the_private_department_channel(): void
    {
        Event::fake([PriorityRecalculated::class]);

        $this->makeWaitingTicket($this->cons);

        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertStatus(200);

        Event::assertDispatched(PriorityRecalculated::class, function (PriorityRecalculated $event) {
            [$public, $private] = $this->splitChannels($event->broadcastOn());
            $this->assertCount(0, $public, 'PriorityRecalculated must never reach a public channel — it carries priority_score.');
            $this->assertCount(1, $private);
            $this->assertSame("private-department.{$this->cons->id}", $private[0]->name);

            $payload = $event->broadcastWith();
            $this->assertArrayHasKey('tickets', $payload);
            $this->assertPayloadHasNoForbiddenKeys($payload);

            return true;
        });
    }

    public function test_payment_verified_broadcasts_only_to_private_channel_with_no_amount_or_insurance_detail(): void
    {
        Event::fake([PaymentVerified::class]);

        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Payment Broadcast Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000098']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_LAB']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->lab->id, 'service_type' => 'Laboratory', 'status' => 'Pending', 'requires_payment' => true]);

        $this->actingAs($this->billingStaff)->postJson("/api/services/{$service->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'Lab Fee', 'amount' => 5000]],
        ])->assertStatus(201);

        Event::assertDispatched(PaymentVerified::class, function (PaymentVerified $event) {
            [$public, $private] = $this->splitChannels($event->broadcastOn());
            $this->assertCount(0, $public, 'PaymentVerified must never reach a public channel.');
            $this->assertCount(1, $private);
            $this->assertSame("private-department.{$this->lab->id}", $private[0]->name);

            $payload = $event->broadcastWith();
            $this->assertPayloadHasNoForbiddenKeys($payload);
            $this->assertSame(['service_id', 'department_id', 'queue_number', 'status'], array_keys($payload));
            $this->assertSame('VERIFIED', $payload['status']);

            return true;
        });
    }
}
