<?php

namespace Tests\Feature;

use App\Models\AlertThreshold;
use App\Models\Notification;
use App\Models\PriorityLevel;
use Illuminate\Support\Facades\Artisan;

/**
 * Phase 8, DoD #2 — a ticket manually pushed past its department+priority
 * threshold triggers exactly ONE Administrator notification when the
 * scheduled command runs; running it again immediately after does NOT
 * create a second alert for the same ticket. This is the actual
 * alert-fatigue requirement, tested explicitly rather than assumed.
 */
class LongWaitingAlertTest extends NotificationTestCase
{
    public function test_overdue_ticket_triggers_exactly_one_alert_and_command_is_idempotent_within_the_suppression_window(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);

        $threshold = AlertThreshold::where('department_id', $this->cons->id)
            ->where('priority_level_id', $ticket->priority_level_id)
            ->firstOrFail();

        // Push the ticket's created_at back well past its threshold —
        // Eloquent's update() re-touches updated_at automatically but we
        // need a specific created_at, so the query builder form is used to
        // avoid the same "custom value silently overwritten" gotcha
        // documented elsewhere in this codebase's tests.
        \App\Models\QueueTicket::whereKey($ticket->id)->update([
            'created_at' => now()->subMinutes($threshold->threshold_minutes + 5),
        ]);

        $this->assertSame(0, Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count());

        Artisan::call('queue:check-long-waiting');

        $this->assertSame(1, Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count());
        $this->assertNotNull($ticket->fresh()->long_wait_alerted_at);

        // Alert fatigue rule: running it again immediately must not create
        // a second alert for the same still-overdue ticket.
        Artisan::call('queue:check-long-waiting');

        $this->assertSame(
            1,
            Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count(),
            'Running the command again within the suppression window must not create a second alert.'
        );
    }

    public function test_ticket_within_threshold_does_not_trigger_an_alert(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        // Freshly created — nowhere near any threshold.

        Artisan::call('queue:check-long-waiting');

        $this->assertSame(0, Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count());
        $this->assertNull($ticket->fresh()->long_wait_alerted_at);
    }

    public function test_ticket_re_alerts_after_the_suppression_window_has_passed(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $threshold = AlertThreshold::where('department_id', $this->cons->id)
            ->where('priority_level_id', $ticket->priority_level_id)
            ->firstOrFail();

        \App\Models\QueueTicket::whereKey($ticket->id)->update([
            'created_at' => now()->subMinutes($threshold->threshold_minutes + 5),
            // Alerted well over 30 minutes ago — the suppression window has expired.
            'long_wait_alerted_at' => now()->subMinutes(45),
        ]);

        Artisan::call('queue:check-long-waiting');

        $this->assertSame(
            1,
            Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count(),
            'A ticket still overdue after the suppression window expired must be re-alerted.'
        );
    }

    public function test_alert_uses_the_correct_threshold_for_a_different_priority_level(): void
    {
        $critical = PriorityLevel::where('name', 'Critical')->first();
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        \App\Models\QueueTicket::whereKey($ticket->id)->update(['priority_level_id' => $critical->id]);

        $criticalThreshold = AlertThreshold::where('department_id', $this->cons->id)
            ->where('priority_level_id', $critical->id)
            ->firstOrFail();

        // Just past the Critical threshold (10 min) but nowhere near the
        // Normal one (45 min) — proves the command looks up the threshold
        // for THIS ticket's actual priority, not a fixed default.
        \App\Models\QueueTicket::whereKey($ticket->id)->update([
            'created_at' => now()->subMinutes($criticalThreshold->threshold_minutes + 1),
        ]);

        Artisan::call('queue:check-long-waiting');

        $this->assertSame(1, Notification::where('type', 'LONG_WAIT_ALERT')->where('queue_ticket_id', $ticket->id)->count());
    }

    public function test_long_wait_alert_notifications_are_administrator_only(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $threshold = AlertThreshold::where('department_id', $this->cons->id)
            ->where('priority_level_id', $ticket->priority_level_id)
            ->firstOrFail();
        \App\Models\QueueTicket::whereKey($ticket->id)->update(['created_at' => now()->subMinutes($threshold->threshold_minutes + 5)]);
        Artisan::call('queue:check-long-waiting');

        $this->actingAs($this->doctor)->getJson('/api/notifications?type=LONG_WAIT_ALERT')->assertStatus(403);

        $administrator = $this->makeUser('Administrator');
        $response = $this->actingAs($administrator)->getJson('/api/notifications?type=LONG_WAIT_ALERT')->assertStatus(200);
        $this->assertNotEmpty($response->json('notifications'));
        $this->assertSame('LONG_WAIT_ALERT', $response->json('notifications.0.type'));
    }
}
