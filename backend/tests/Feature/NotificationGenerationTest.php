<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8 — Notification generation. Despite the spec's claim that a
 * NotificationService stub "already exists from Phase 2", no Notification
 * model, table, or service existed anywhere before this phase (verified
 * directly against the codebase) — this is from-scratch coverage, not a
 * regression check against prior behavior.
 */
class NotificationGenerationTest extends NotificationTestCase
{
    public function test_registering_a_visit_creates_a_waiting_notification(): void
    {
        $patientRes = $this->actingAs($this->registrationStaff)->postJson('/api/patients', [
            'name' => 'Waiting Notif Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000200',
        ])->assertStatus(201);

        $visitRes = $this->actingAs($this->registrationStaff)->postJson('/api/visits', [
            'patient_id' => $patientRes->json('patient.id'),
            'patient_type' => 'Normal',
            'payment_method' => 'Cash',
            'department_id' => $this->cons->id,
        ])->assertStatus(201);

        $visitId = $visitRes->json('visit.id');

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visitId,
            'type' => 'WAITING',
        ]);

        $notification = Notification::where('visit_id', $visitId)->where('type', 'WAITING')->first();
        $this->assertStringContainsString('Consultation', $notification->message);
        // Never leaks another visit's data — trivially true here since the
        // message is built solely from this ticket's own fields, but assert
        // the shape directly rather than just trusting that.
        $this->assertStringNotContainsString('Waiting Notif Patient', $notification->message);
    }

    public function test_calling_next_patient_creates_a_called_notification(): void
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);

        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visit->id,
            'queue_ticket_id' => $ticket->id,
            'type' => 'CALLED',
        ]);
    }

    public function test_manually_calling_a_specific_ticket_also_creates_a_called_notification(): void
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);

        // The OTHER path to CALLED — TicketTransitionService, not
        // PriorityEngine::callNext — must be covered too.
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visit->id,
            'queue_ticket_id' => $ticket->id,
            'type' => 'CALLED',
        ]);
        // Exactly one — no double notification from some other path.
        $this->assertSame(1, Notification::where('queue_ticket_id', $ticket->id)->where('type', 'CALLED')->count());
    }

    public function test_completing_a_ticket_creates_a_completed_notification(): void
    {
        [$visit, $ticket] = $this->makeWaitingVisitAndTicket($this->cons, requiresPayment: true);
        $this->verifyPayment(Service::find($ticket->service_id));

        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/start-service")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticket->id}/complete")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visit->id,
            'queue_ticket_id' => $ticket->id,
            'type' => 'COMPLETED',
        ]);
    }

    public function test_requesting_a_next_service_creates_a_transferred_notification(): void
    {
        Log::spy();

        [$visit, $consTicket] = $this->makeWaitingVisitAndTicket($this->cons, requiresPayment: true);
        $this->verifyPayment(Service::find($consTicket->service_id));
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicket->id}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$consTicket->id}/start-service")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/visits/{$visit->id}/clinical-record", [
            'requested_tests_other' => 'Full blood count.',
        ])->assertStatus(200);

        $labServiceRes = $this->actingAs($this->doctor)
            ->postJson("/api/visits/{$visit->id}/services", ['department_id' => $this->lab->id])
            ->assertStatus(201);

        $labTicketId = $labServiceRes->json('service.queue_ticket.id');

        $this->assertDatabaseHas('notifications', [
            'visit_id' => $visit->id,
            'queue_ticket_id' => $labTicketId,
            'type' => 'TRANSFERRED',
        ]);
        $notification = Notification::where('queue_ticket_id', $labTicketId)->where('type', 'TRANSFERRED')->first();
        $this->assertStringContainsString('Consultation', $notification->message);
        $this->assertStringContainsString('Laboratory', $notification->message);

        // A transfer must re-send the tracking link (with the NEW
        // department/ticket/counter) too, not just create the in-app
        // notification row — otherwise the patient has no fresh link
        // telling them where to physically go next.
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === '[SMS stub] Would send transfer notice'
                && str_contains($context['message'], 'Laboratory')
                && str_contains($context['message'], (string) $visit->tracking_token)
            );
    }

    public function test_approaching_notification_fires_once_and_never_duplicates_on_recalculation(): void
    {
        $tickets = [];
        for ($i = 0; $i < 4; $i++) {
            [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
            $tickets[] = $ticket;
        }

        // callNext recalculates every WAITING ticket's score (firing
        // PriorityRecalculated) THEN marks the top-scored one CALLED, all
        // within one transaction. The listener only notifies tickets still
        // WAITING at the moment it actually runs (post-commit) — by then
        // the just-called ticket no longer is, so it gets a CALLED
        // notification instead of APPROACHING, never both.
        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertStatus(200);

        $calledTicketId = $tickets[0]->id;
        $this->assertSame(0, Notification::where('type', 'APPROACHING')->where('queue_ticket_id', $calledTicketId)->count());
        $this->assertGreaterThan(0, Notification::where('type', 'APPROACHING')->count(), 'At least one of the remaining WAITING tickets should be within the approaching threshold.');

        // The alert-fatigue rule: re-running call-next recalculates scores
        // again for the SAME still-waiting tickets — none of them may gain
        // a second APPROACHING row for it.
        $countsBeforeSecondCall = Notification::where('type', 'APPROACHING')->pluck('queue_ticket_id')->countBy();
        $this->actingAs($this->doctor)->postJson("/api/departments/{$this->cons->id}/call-next")->assertStatus(200);
        $countsAfterSecondCall = Notification::where('type', 'APPROACHING')->pluck('queue_ticket_id')->countBy();

        foreach ($countsBeforeSecondCall as $ticketId => $countBefore) {
            $this->assertSame(1, $countBefore, "Ticket {$ticketId} must never have more than one APPROACHING notification.");
            $this->assertSame($countBefore, $countsAfterSecondCall[$ticketId], "Ticket {$ticketId}'s APPROACHING count must not increase on re-recalculation.");
        }
    }

    public function test_tracking_endpoint_returns_this_visits_own_notifications_only(): void
    {
        [$visitA, $ticketA] = $this->makeWaitingVisitAndTicket($this->cons);
        [$visitB, $ticketB] = $this->makeWaitingVisitAndTicket($this->cons);

        // makeWaitingVisitAndTicket creates rows directly via Eloquent (no
        // WAITING notification, that's only generated inside
        // VisitController::store()) — call each ticket so both visits have
        // at least one real, distinguishable notification to compare.
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticketA->id}/call")->assertStatus(200);
        $this->actingAs($this->doctor)->patchJson("/api/queue-tickets/{$ticketB->id}/call")->assertStatus(200);
        $visitBMessages = Notification::where('visit_id', $visitB->id)->pluck('message');
        $this->assertNotEmpty($visitBMessages);

        $response = $this->getJson("/api/track/{$visitA->tracking_token}")->assertStatus(200);
        $notifications = $response->json('notifications');

        $this->assertNotEmpty($notifications);
        foreach ($notifications as $notification) {
            $this->assertArrayHasKey('type', $notification);
            $this->assertArrayHasKey('message', $notification);
            $this->assertFalse(
                $visitBMessages->contains($notification['message']),
                'Visit A\'s notification feed must never contain a message generated for visit B.'
            );
        }
    }
}
