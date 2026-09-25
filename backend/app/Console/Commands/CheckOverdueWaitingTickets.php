<?php

namespace App\Console\Commands;

use App\Models\QueueTicket;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (see bootstrap/app.php's withSchedule). Alerts the
 * staff who serve a department when a patient has sat in WAITING longer
 * than the expected wait without being called. Unlike
 * queue:check-long-waiting (Administrator-only, per-priority thresholds,
 * re-alerts every 30 min), this is one short fixed limit and fires once per
 * ticket — the alert bell in the dashboard header lists these until the
 * ticket is called.
 */
class CheckOverdueWaitingTickets extends Command
{
    protected $signature = 'queue:check-overdue-waiting';

    protected $description = 'Alerts department staff about WAITING tickets not called within the expected wait time, once per ticket.';

    public const EXPECTED_WAIT_MINUTES = 5;

    public function handle(NotificationService $notifications): int
    {
        $tickets = QueueTicket::query()
            ->where('status', 'WAITING')
            ->whereNull('overdue_alerted_at')
            ->where('created_at', '<=', now()->subMinutes(self::EXPECTED_WAIT_MINUTES))
            ->with('service.department')
            ->get();

        foreach ($tickets as $ticket) {
            $waitingMinutes = (int) now()->diffInMinutes($ticket->created_at, absolute: true);

            $notifications->alertOverdueWait($ticket, $ticket->service->department, $waitingMinutes, self::EXPECTED_WAIT_MINUTES);
            $ticket->update(['overdue_alerted_at' => now()]);
        }

        $this->info("Sent {$tickets->count()} overdue-wait alert(s).");

        return self::SUCCESS;
    }
}
