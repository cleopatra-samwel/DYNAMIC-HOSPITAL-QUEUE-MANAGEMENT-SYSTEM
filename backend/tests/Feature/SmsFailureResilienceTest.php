<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Registration must never be blocked by a notification/SMS failure. The
 * stub sendTrackingLink() can't actually throw today (it's just a log
 * call — confirmed directly against a malformed contact value), but
 * VisitController::store()'s try/catch around it is forward-looking
 * protection for once a real gateway call replaces that stub, so this
 * simulates that future failure directly (partial mock) rather than
 * waiting for a real gateway to exist before it can be tested at all.
 */
class SmsFailureResilienceTest extends NotificationTestCase
{
    public function test_visit_creation_succeeds_and_logs_a_distinguishable_error_when_sms_delivery_throws(): void
    {
        $this->partialMock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTrackingLink')
                ->once()
                ->andThrow(new \RuntimeException('SMS gateway timeout'));
        });

        Log::spy();

        $patientRes = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'SMS Failure Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000210',
        ])->assertStatus(201);

        // The whole point: registration succeeds regardless of the SMS failure.
        $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientRes->json('patient.id'),
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        // Distinguishable, not JUST a bare report($e) — report($e) itself
        // also logs an error() line by default (that's fine, it's what
        // feeds any configured monitoring integration), so this doesn't
        // constrain the total call count, only that OUR clearly labeled
        // line was among them and findable without digging through an
        // unlabeled stack trace.
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => str_contains($message, 'SMS tracking link delivery failed')
                && $context['exception'] === 'SMS gateway timeout'
                && isset($context['visit_id'])
                && isset($context['patient_id'])
            );
    }
}
