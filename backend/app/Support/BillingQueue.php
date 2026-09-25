<?php

namespace App\Support;

use App\Events\TicketStatusChanged;
use App\Models\Department;
use App\Models\PriorityLevel;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;

/**
 * The Cashier/Billing queue. Every service that requires payment
 * (Consultation/Laboratory/Pharmacy, see PaymentGate) gets a companion
 * ticket in the Billing department, so the cashier calls patients to pay the
 * same way other departments call theirs (call-next, display board, voice
 * announcement).
 *
 * The companion lives in its own Service row (billing_for_service_id points
 * at the service being paid for) and never becomes IN_SERVICE, so it can
 * never trip the "one active service per visit" Critical Rule, and it stays
 * out of the clinical journey (see Service::scopeClinical). Verifying the
 * payment completes it; cancelling the paid-for service cancels it.
 * Does nothing when no active Billing department exists.
 */
class BillingQueue
{
    public static function open(Service $target, Visit $visit, ?User $actingUser): ?QueueTicket
    {
        if (! $target->requires_payment) {
            return null;
        }

        $billing = Department::where('dept_code', 'BILL')->where('is_active', true)->first();

        if (! $billing) {
            return null;
        }

        $service = Service::create([
            'visit_id' => $visit->id,
            'department_id' => $billing->id,
            'billing_for_service_id' => $target->id,
            'service_type' => $billing->dept_name,
            'status' => 'Pending',
            'requires_payment' => false,
        ]);

        $priorityLevel = PriorityLevel::where('name', $visit->patient_type === 'Emergency' ? 'Critical' : 'Normal')->firstOrFail();

        $ticket = QueueTicket::create([
            'service_id' => $service->id,
            'priority_level_id' => $priorityLevel->id,
            'queue_number' => QueueNumberGenerator::next($billing),
            'priority_score' => $priorityLevel->weight,
            'status' => 'WAITING',
        ]);

        QueueEvent::create([
            'queue_ticket_id' => $ticket->id,
            'event_type' => 'CREATED',
            'performed_by' => $actingUser?->id,
            'event_time' => now(),
        ]);

        return $ticket;
    }

    /** Payment verified: the patient has been served by the cashier. */
    public static function complete(Service $target, User $actingUser): void
    {
        self::close($target, $actingUser, 'COMPLETED', 'Completed');
    }

    /** The paid-for service was cancelled / the patient never showed: nothing left to pay. */
    public static function cancel(Service $target, User $actingUser): void
    {
        self::close($target, $actingUser, 'CANCELLED', 'Cancelled');
    }

    private static function close(Service $target, User $actingUser, string $ticketStatus, string $serviceStatus): void
    {
        $billingService = Service::where('billing_for_service_id', $target->id)->with('queueTicket')->first();
        $ticket = $billingService?->queueTicket;

        if (! $ticket || ! in_array($ticket->status, ['WAITING', 'CALLED', 'ON_HOLD'], true)) {
            return;
        }

        $ticket->update(array_filter([
            'status' => $ticketStatus,
            'completed_at' => $ticketStatus === 'COMPLETED' ? now() : null,
        ]));
        $billingService->update(['status' => $serviceStatus]);

        QueueEvent::create([
            'queue_ticket_id' => $ticket->id,
            'event_type' => $ticketStatus,
            'performed_by' => $actingUser->id,
            'event_time' => now(),
        ]);

        // Calling the Billing ticket moved the visit into "IN_BILL"; put it
        // back to where the paid-for (clinical) ticket actually is.
        $targetTicket = $target->queueTicket;
        if ($targetTicket) {
            $target->visit->update([
                'overall_status' => QueueJourney::stateFor($target->department->dept_code, $targetTicket->status),
            ]);
        }

        TicketStatusChanged::dispatch($ticket->fresh(['service.department', 'service.visit']));
    }
}
