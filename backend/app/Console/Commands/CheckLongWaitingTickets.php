<?php

namespace App\Console\Commands;

use App\Models\AlertThreshold;
use App\Models\QueueTicket;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Phase 8 — scheduled (see bootstrap/app.php's withSchedule), not
 * event-driven: a ticket can breach its threshold just by sitting still,
 * with no state-changing action to hang a listener off of.
 *
 * The alert-fatigue rule: a ticket already alerted within the
 * suppression window is skipped entirely, so running this every minute
 * doesn't spam Administrators with the same overdue ticket 30 times in a
 * row — it re-alerts only after the ticket has STILL been overdue for a
 * further suppression window.
 */
class CheckLongWaitingTickets extends Command
{
    protected $signature = 'queue:check-long-waiting';

    protected $description = 'Alerts Administrators about WAITING tickets that have exceeded their department+priority threshold, at most once per suppression window.';

    private const SUPPRESSION_MINUTES = 30;

    public function handle(NotificationService $notifications): int
    {
        $thresholds = AlertThreshold::all()
            ->keyBy(fn (AlertThreshold $t) => "{$t->department_id}:{$t->priority_level_id}");

        $tickets = QueueTicket::query()
            ->where('status', 'WAITING')
            ->with(['service.department', 'priorityLevel'])
            ->get();

        $alertedCount = 0;

        foreach ($tickets as $ticket) {
            $department = $ticket->service->department;
            $threshold = $thresholds->get("{$department->id}:{$ticket->priority_level_id}");

            if (! $threshold) {
                continue;
            }

            $waitingMinutes = now()->diffInMinutes($ticket->created_at, absolute: true);

            if ($waitingMinutes < $threshold->threshold_minutes) {
                continue;
            }

            $alreadyAlertedRecently = $ticket->long_wait_alerted_at !== null
                && $ticket->long_wait_alerted_at->gt(now()->subMinutes(self::SUPPRESSION_MINUTES));

            if ($alreadyAlertedRecently) {
                continue;
            }

            $notifications->alertLongWait($ticket, $department, $waitingMinutes, $threshold->threshold_minutes);
            $ticket->update(['long_wait_alerted_at' => now()]);
            $alertedCount++;
        }

        $this->info("Checked {$tickets->count()} waiting ticket(s), sent {$alertedCount} long-wait alert(s).");

        return self::SUCCESS;
    }
}
