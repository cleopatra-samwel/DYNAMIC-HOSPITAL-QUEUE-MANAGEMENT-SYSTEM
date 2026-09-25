<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\Artisan;

/**
 * A patient still WAITING past the expected wait (5 min) raises exactly one
 * staff alert for that department; the alert bell endpoint shows it only to
 * staff of that department and only until the ticket is called.
 */
class OverdueWaitAlertTest extends NotificationTestCase
{
    public function test_ticket_waiting_over_five_minutes_alerts_once_and_only_the_departments_own_staff_see_it(): void
    {
        [, $overdue] = $this->makeWaitingVisitAndTicket($this->cons);
        [, $fresh] = $this->makeWaitingVisitAndTicket($this->cons);
        QueueTicket::whereKey($overdue->id)->update(['created_at' => now()->subMinutes(7)]);

        Artisan::call('queue:check-overdue-waiting');
        Artisan::call('queue:check-overdue-waiting');

        $this->assertSame(1, Notification::where('type', 'WAIT_OVERDUE')->where('queue_ticket_id', $overdue->id)->count());
        $this->assertSame(0, Notification::where('type', 'WAIT_OVERDUE')->where('queue_ticket_id', $fresh->id)->count());

        $this->actingAs($this->doctor)->getJson('/api/notifications/staff-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'alerts')
            ->assertJsonPath('alerts.0.queue_ticket.queue_number', $overdue->queue_number);

        $this->actingAs($this->makeUser('Laboratory Staff'))->getJson('/api/notifications/staff-alerts')->assertOk()->assertJsonCount(0, 'alerts');
    }

    public function test_alert_disappears_once_the_patient_is_called(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        QueueTicket::whereKey($ticket->id)->update(['created_at' => now()->subMinutes(7)]);
        Artisan::call('queue:check-overdue-waiting');

        QueueTicket::whereKey($ticket->id)->update(['status' => 'CALLED']);

        $this->actingAs($this->doctor)->getJson('/api/notifications/staff-alerts')->assertOk()->assertJsonCount(0, 'alerts');

        // The Notification page's history view still lists it.
        $this->actingAs($this->doctor)->getJson('/api/notifications/staff-alerts?include_resolved=1')->assertOk()->assertJsonCount(1, 'alerts');
    }
}
