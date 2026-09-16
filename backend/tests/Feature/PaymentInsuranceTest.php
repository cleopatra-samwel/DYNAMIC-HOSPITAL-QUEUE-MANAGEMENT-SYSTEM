<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\QueueTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

class PaymentInsuranceTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    private Department $cons;

    private Department $lab;

    private Department $pharm;

    private User $registrationStaff;

    private User $doctor;

    private User $labStaff;

    private User $pharmacyStaff;

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
        $this->pharm = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true]);

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
        $this->labStaff = $this->makeUser('Laboratory Staff');
        $this->pharmacyStaff = $this->makeUser('Pharmacy Staff');
        $this->billingStaff = $this->makeUser('Cashier/Billing Staff');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'.p6@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeService(Department $department, string $paymentMethod = 'Cash', string $ticketStatus = 'CALLED'): array
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Payment Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000000']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => $paymentMethod, 'overall_status' => 'WAITING_'.$department->dept_code]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => $department->dept_name, 'status' => 'Pending', 'requires_payment' => true]);
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => strtoupper($department->dept_code).'-'.uniqid(), 'priority_score' => 10, 'status' => $ticketStatus]);

        return [$visit, $service, $ticket];
    }

    /**
     * DoD #1: a Cash patient cannot reach IN_SERVICE at Consultation,
     * Laboratory, OR Pharmacy without a VERIFIED payment for that specific
     * service — all three, not just two.
     */
    public function test_payment_gate_blocks_in_service_at_all_three_billable_departments(): void
    {
        $cases = [
            [$this->cons, $this->doctor],
            [$this->lab, $this->labStaff],
            [$this->pharm, $this->pharmacyStaff],
        ];

        foreach ($cases as [$department, $staff]) {
            [, $service, $ticket] = $this->makeService($department);

            // Blocked before verification.
            $this->actingAs($staff)
                ->patchJson("/api/queue-tickets/{$ticket->id}/start-service")
                ->assertStatus(402);
            $this->assertSame('CALLED', $ticket->fresh()->status, "{$department->dept_code} ticket must not have started without payment.");

            // Cashier verifies — now it succeeds.
            $this->actingAs($this->billingStaff)
                ->postJson("/api/services/{$service->id}/payments/verify", [
                    'method' => 'Cash',
                    'items' => [['catalog_type' => 'other', 'custom_label' => 'Consultation Fee', 'amount' => 5000]],
                ])
                ->assertStatus(201)
                ->assertJsonPath('payment.status', 'VERIFIED');

            $this->actingAs($staff)
                ->patchJson("/api/queue-tickets/{$ticket->id}/start-service")
                ->assertStatus(200)
                ->assertJsonPath('ticket.status', 'IN_SERVICE');
        }
    }

    public function test_insurance_payment_verification_requires_provider_and_ref(): void
    {
        [, $service] = $this->makeService($this->lab, 'Insurance');

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Insurance',
                'items' => [['catalog_type' => 'other', 'custom_label' => 'Lab Fee', 'amount' => 5000]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Insurance',
                'insurance_provider' => 'NHIF',
                'insurance_ref' => 'REF-12345',
                'items' => [['catalog_type' => 'other', 'custom_label' => 'Lab Fee', 'amount' => 5000]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('payment.status', 'VERIFIED')
            ->assertJsonPath('payment.insurance_provider', 'NHIF');
    }

    public function test_only_billing_staff_or_administrator_may_verify_payment(): void
    {
        [, $service] = $this->makeService($this->cons);

        $items = ['items' => [['catalog_type' => 'other', 'custom_label' => 'Fee', 'amount' => 1000]]];

        $this->actingAs($this->doctor)
            ->postJson("/api/services/{$service->id}/payments/verify", ['method' => 'Cash'] + $items)
            ->assertStatus(403);

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", ['method' => 'Cash'] + $items)
            ->assertStatus(403);
    }

    /** DoD #3 (Part 6): payments.amount is always the SUM of its payment_items, never client-set directly. */
    public function test_verifying_with_multiple_catalog_items_sums_to_the_correct_total(): void
    {
        [, $service] = $this->makeService($this->lab);
        $testA = \App\Models\LabTestCatalog::create(['name' => 'Test A', 'price' => 5000, 'active' => true]);
        $testB = \App\Models\LabTestCatalog::create(['name' => 'Test B', 'price' => 7500, 'active' => true]);

        $response = $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Cash',
                'items' => [
                    ['catalog_type' => 'lab_test', 'catalog_item_id' => $testA->id, 'amount' => 5000],
                    ['catalog_type' => 'lab_test', 'catalog_item_id' => $testB->id, 'amount' => 7500],
                    ['catalog_type' => 'other', 'custom_label' => 'Sundries', 'amount' => 1000],
                ],
            ])
            ->assertStatus(201);

        $this->assertEquals(13500, (float) $response->json('payment.amount'));
        $this->assertCount(3, $response->json('payment.items'));

        $payment = \App\Models\Payment::where('service_id', $service->id)->first();
        $this->assertEquals(13500, (float) $payment->amount);
        $this->assertSame(3, $payment->items()->count());
    }

    /** The "Other" fallback: a custom label + manual price when nothing in the catalog matches. */
    public function test_other_catalog_type_requires_a_custom_label(): void
    {
        [, $service] = $this->makeService($this->pharm);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Cash',
                'items' => [['catalog_type' => 'other', 'amount' => 2000]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Cash',
                'items' => [['catalog_type' => 'other', 'custom_label' => 'Unlisted medication', 'amount' => 2000]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('payment.items.0.custom_label', 'Unlisted medication');
    }

    /**
     * payment_items.amount is decimal(12,2) — without an upper-bound
     * validation rule, a mistyped amount (extra digits) doesn't fail
     * validation, it crashes with a raw Postgres "numeric field overflow"
     * 500 error instead (reproduced live, see PaymentController::verify()).
     */
    public function test_an_amount_exceeding_the_database_columns_precision_is_rejected_with_a_validation_error(): void
    {
        [, $service] = $this->makeService($this->pharm);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Cash',
                'items' => [['catalog_type' => 'other', 'custom_label' => 'Fee', 'amount' => 3456666666667]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.amount']);
    }

    public function test_a_catalog_item_id_that_does_not_exist_in_its_catalog_is_rejected(): void
    {
        [, $service] = $this->makeService($this->lab);

        $this->actingAs($this->billingStaff)
            ->postJson("/api/services/{$service->id}/payments/verify", [
                'method' => 'Cash',
                'items' => [['catalog_type' => 'lab_test', 'catalog_item_id' => 999999, 'amount' => 5000]],
            ])
            ->assertStatus(422);
    }

    /** Re-verifying (e.g. correcting a mistaken item) replaces the item list outright rather than appending duplicates. */
    public function test_re_verifying_replaces_the_previous_items_not_appends(): void
    {
        [, $service] = $this->makeService($this->lab);

        $this->actingAs($this->billingStaff)->postJson("/api/services/{$service->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'First attempt', 'amount' => 1000]],
        ])->assertStatus(201);

        $response = $this->actingAs($this->billingStaff)->postJson("/api/services/{$service->id}/payments/verify", [
            'method' => 'Cash',
            'items' => [['catalog_type' => 'other', 'custom_label' => 'Corrected amount', 'amount' => 3000]],
        ])->assertStatus(201);

        $this->assertCount(1, $response->json('payment.items'));
        $this->assertEquals(3000, (float) $response->json('payment.amount'));
    }

    /** DoD #4: the Pending Payments list spans all three billable departments. */
    public function test_pending_payments_lists_all_three_departments(): void
    {
        [, $consService] = $this->makeService($this->cons);
        [, $labService] = $this->makeService($this->lab);
        [, $pharmService] = $this->makeService($this->pharm);

        // One of them gets verified — it must drop off the pending list.
        $this->verifyPayment($labService);

        $response = $this->actingAs($this->billingStaff)->getJson('/api/payments/pending')->assertStatus(200);
        $departments = collect($response->json('pending'))->pluck('department');

        $this->assertTrue($departments->contains('Consultation'));
        $this->assertTrue($departments->contains('Pharmacy'));
        $this->assertFalse($departments->contains('Laboratory'), 'The verified Laboratory service must not still be pending.');

        $serviceIds = collect($response->json('pending'))->pluck('service_id');
        $this->assertTrue($serviceIds->contains($consService->id));
        $this->assertTrue($serviceIds->contains($pharmService->id));
        $this->assertFalse($serviceIds->contains($labService->id));
    }

    public function test_only_billing_staff_or_administrator_may_view_pending_payments(): void
    {
        $this->actingAs($this->doctor)->getJson('/api/payments/pending')->assertStatus(403);
    }

    /**
     * DoD #2: an insurance card can only be received and released by
     * Registration Staff (or Administrator) — Cashier/Billing gets 403 for
     * both, tested directly. This is the deliberate ownership change from
     * earlier planning (Cashier used to hold cards; now Registration does).
     */
    public function test_only_registration_staff_may_receive_or_release_an_insurance_card(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Insurance Card Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000005']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_CONS']);

        // Cashier/Billing Staff must be rejected outright — not just
        // hidden in the UI.
        $this->actingAs($this->billingStaff)
            ->postJson("/api/visits/{$visit->id}/insurance-card")
            ->assertStatus(403);

        // Loading the receivedBy relation serializes to the same JSON key
        // as the raw received_by column, so the response carries the full
        // user object there (nicer for the frontend) — assert its id.
        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/insurance-card")
            ->assertStatus(201)
            ->assertJsonPath('insurance_card.received_by.id', $this->registrationStaff->id);

        $visit->update(['overall_status' => 'COMPLETED']);

        $this->actingAs($this->billingStaff)
            ->patchJson("/api/visits/{$visit->id}/insurance-card/release", ['signoff_confirmation' => 'Handed back to patient.'])
            ->assertStatus(403);

        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/insurance-card/release", ['signoff_confirmation' => 'Handed back to patient.'])
            ->assertStatus(200)
            ->assertJsonPath('insurance_card.released_by.id', $this->registrationStaff->id);
    }

    /**
     * The success path, isolated from the 403 role checks above: once the
     * visit is COMPLETED, Registration Staff releasing the card gets a 200
     * with released_by/released_at properly set — and the original
     * received_by/received_at survive untouched, since release only adds
     * fields, it doesn't overwrite the receipt record.
     */
    public function test_registration_staff_successfully_releases_card_after_visit_completion(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Successful Release Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000013']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_CONS']);

        $receiveResponse = $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/insurance-card")
            ->assertStatus(201);
        $receivedAt = $receiveResponse->json('insurance_card.received_at');

        $visit->update(['overall_status' => 'COMPLETED']);

        $before = now();
        $response = $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/insurance-card/release", ['signoff_confirmation' => 'Card handed back to patient at discharge.']);

        $response->assertStatus(200)
            ->assertJsonPath('insurance_card.released_by.id', $this->registrationStaff->id)
            ->assertJsonPath('insurance_card.released_by.name', $this->registrationStaff->name)
            ->assertJsonPath('insurance_card.signoff_confirmation', 'Card handed back to patient at discharge.')
            // Receipt data must be untouched by the release call.
            ->assertJsonPath('insurance_card.received_by.id', $this->registrationStaff->id)
            ->assertJsonPath('insurance_card.received_at', $receivedAt);

        $releasedAt = $response->json('insurance_card.released_at');
        $this->assertNotNull($releasedAt, 'released_at must be set on a successful release.');
        // Timestamp columns can truncate sub-second precision, so compare
        // with a small tolerance rather than a strict betweenIncluded().
        $this->assertLessThanOrEqual(2, abs(\Carbon\Carbon::parse($releasedAt)->diffInSeconds($before)), 'released_at must reflect when the release happened.');

        // Persisted state matches the response, not just an in-memory value.
        $card = \App\Models\InsuranceCard::where('visit_id', $visit->id)->first();
        $this->assertSame($this->registrationStaff->id, $card->released_by);
        $this->assertNotNull($card->released_at);
        $this->assertSame('Card handed back to patient at discharge.', $card->signoff_confirmation);
    }

    public function test_insurance_card_cannot_be_received_for_a_cash_visit(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Cash Visit Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000006']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'WAITING_CONS']);

        $this->actingAs($this->registrationStaff)
            ->postJson("/api/visits/{$visit->id}/insurance-card")
            ->assertStatus(422);
    }

    /**
     * Receiving a card is a deliberate staff confirmation, never automatic
     * on visit creation — an Insurance visit sits in "pending receipt"
     * until someone explicitly confirms the card is physically in hand.
     */
    public function test_insurance_visit_sits_in_pending_receipt_until_explicitly_confirmed(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Pending Receipt Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000011']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_CONS']);

        // Freshly registered, nobody has confirmed receipt yet.
        $pending = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards/pending-receipt')->assertStatus(200);
        $this->assertTrue(collect($pending->json('pending_receipt'))->pluck('visit_id')->contains($visit->id));

        $inCustody = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards?status=in_custody')->assertStatus(200);
        $this->assertFalse(collect($inCustody->json('insurance_cards'))->pluck('visit_id')->contains($visit->id), 'Must not appear as in-custody before anyone confirmed receipt.');

        // Staff explicitly confirms — now it moves from pending to in custody.
        $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit->id}/insurance-card")->assertStatus(201);

        $pendingAfter = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards/pending-receipt')->assertStatus(200);
        $this->assertFalse(collect($pendingAfter->json('pending_receipt'))->pluck('visit_id')->contains($visit->id));

        $inCustodyAfter = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards?status=in_custody')->assertStatus(200);
        $this->assertTrue(collect($inCustodyAfter->json('insurance_cards'))->pluck('visit_id')->contains($visit->id));
    }

    public function test_insurance_card_cannot_be_released_before_visit_is_completed(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Not Done Yet Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000007']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'IN_PHARM']);

        $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit->id}/insurance-card")->assertStatus(201);

        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/insurance-card/release", ['signoff_confirmation' => 'Too early.'])
            ->assertStatus(422);
    }

    /**
     * DoD #3: the visit reaches COMPLETED as soon as its services are all
     * Completed/Cancelled, independent of whether the insurance card has
     * been released — release is a separate, final step afterward.
     */
    public function test_visit_completes_independently_of_insurance_card_release_order(): void
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Card Order Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000008']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'IN_PHARM']);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $pharmService = Service::create(['visit_id' => $visit->id, 'department_id' => $this->pharm->id, 'service_type' => 'Pharmacy', 'status' => 'Completed', 'requires_payment' => true]);
        QueueTicket::create(['service_id' => $pharmService->id, 'priority_level_id' => $normal->id, 'queue_number' => 'PHARM-ORDER', 'priority_score' => 10, 'status' => 'COMPLETED']);

        $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit->id}/insurance-card")->assertStatus(201);

        // Card is still in custody (not released) — completion must not be blocked by that.
        $this->actingAs($this->pharmacyStaff)
            ->patchJson("/api/visits/{$visit->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('visit.overall_status', 'COMPLETED');

        // Only now, after completion, can the card be released.
        $this->actingAs($this->registrationStaff)
            ->patchJson("/api/visits/{$visit->id}/insurance-card/release", ['signoff_confirmation' => 'Returned after closure.'])
            ->assertStatus(200);
    }

    public function test_insurance_cards_index_separates_in_custody_from_released(): void
    {
        $patient1 = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Custody A', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000009']);
        $visit1 = Visit::create(['patient_id' => $patient1->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'WAITING_CONS']);
        $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit1->id}/insurance-card")->assertStatus(201);

        $patient2 = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Custody B', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'contact' => '0700000010']);
        $visit2 = Visit::create(['patient_id' => $patient2->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Insurance', 'overall_status' => 'COMPLETED']);
        $this->actingAs($this->registrationStaff)->postJson("/api/visits/{$visit2->id}/insurance-card")->assertStatus(201);
        $this->actingAs($this->registrationStaff)->patchJson("/api/visits/{$visit2->id}/insurance-card/release", ['signoff_confirmation' => 'Done.'])->assertStatus(200);

        $inCustody = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards?status=in_custody')->assertStatus(200);
        $released = $this->actingAs($this->registrationStaff)->getJson('/api/insurance-cards?status=released')->assertStatus(200);

        $inCustodyVisitIds = collect($inCustody->json('insurance_cards'))->pluck('visit_id');
        $releasedVisitIds = collect($released->json('insurance_cards'))->pluck('visit_id');

        $this->assertTrue($inCustodyVisitIds->contains($visit1->id));
        $this->assertFalse($inCustodyVisitIds->contains($visit2->id));
        $this->assertTrue($releasedVisitIds->contains($visit2->id));
        $this->assertFalse($releasedVisitIds->contains($visit1->id));
    }
}
