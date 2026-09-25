<?php

namespace App\Services;

use App\Events\TicketStatusChanged;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;
use App\Support\BillingQueue;
use App\Support\DepartmentRoles;
use App\Support\PaymentGate;
use App\Support\QueueJourney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 3/5 queue ticket status lifecycle (WAITING -> CALLED ->
 * IN_SERVICE -> COMPLETED, plus CANCELLED/NO_SHOW/ON_HOLD/TRANSFERRED),
 * extracted out of QueueTicketController so it's directly callable from a
 * console command — needed to genuinely load-test the Critical Rule's
 * lockForUpdate() under real concurrent execution (see
 * app/Console/Commands/StartServiceOnce.php and
 * tests/Feature/CriticalRuleConcurrencyTest.php), the same way
 * PriorityEngine::callNext() already is for Phase 4.
 */
class TicketTransitionService
{
    public const TRANSITIONS = [
        'call' => ['from' => ['WAITING', 'ON_HOLD'], 'to' => 'CALLED', 'timestamp' => 'called_at'],
        'start-service' => ['from' => ['CALLED'], 'to' => 'IN_SERVICE', 'timestamp' => 'service_started_at'],
        'complete' => ['from' => ['IN_SERVICE'], 'to' => 'COMPLETED', 'timestamp' => 'completed_at'],
        'no-show' => ['from' => ['CALLED'], 'to' => 'NO_SHOW', 'timestamp' => null],
        'cancel' => ['from' => ['WAITING', 'CALLED', 'IN_SERVICE', 'ON_HOLD'], 'to' => 'CANCELLED', 'timestamp' => null],
        'hold' => ['from' => ['WAITING', 'CALLED'], 'to' => 'ON_HOLD', 'timestamp' => null],
        'transfer' => ['from' => ['WAITING', 'CALLED', 'ON_HOLD'], 'to' => 'TRANSFERRED', 'timestamp' => null],
    ];

    /** Mirrors the ticket's new status onto its parent Service record. */
    private const SERVICE_STATUS = [
        'IN_SERVICE' => 'Active',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        'NO_SHOW' => 'Cancelled',
    ];

    public function apply(QueueTicket $queueTicket, string $action, User $actingUser): QueueTicket
    {
        $rule = self::TRANSITIONS[$action];

        $queueTicket->loadMissing(['service.department', 'service.visit']);

        abort_unless(
            DepartmentRoles::userCanActOn($actingUser, $queueTicket->service->department->dept_code),
            403,
            'You are not permitted to act on tickets for this department.'
        );

        DB::transaction(function () use ($queueTicket, $rule, $actingUser) {
            // Lock the whole visit FIRST — this is the anchor that makes the
            // Critical Rule check safe under concurrency. A lockForUpdate()
            // that only targets rows already matching status=IN_SERVICE
            // locks nothing when neither competing ticket has reached that
            // status yet, so two transitions racing to become the first
            // IN_SERVICE ticket for the same visit could otherwise both
            // pass the check (verified failing before this fix, via
            // CriticalRuleConcurrencyTest — two real concurrent processes
            // both got OK). Locking a row that unconditionally exists
            // (the visit) forces the second transaction to block until the
            // first commits, so it re-reads the post-commit state.
            $visit = Visit::query()->whereKey($queueTicket->service->visit_id)->lockForUpdate()->firstOrFail();

            $locked = QueueTicket::query()->whereKey($queueTicket->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                in_array($locked->status, $rule['from'], true),
                422,
                "Cannot move ticket {$queueTicket->queue_number} from {$locked->status} to {$rule['to']}."
            );

            // Billable departments (Pharmacy) may only call a patient whose
            // payment has been verified — pay first, then be called.
            if ($rule['to'] === 'CALLED' && PaymentGate::requiresPayment($queueTicket->service->department->dept_code)) {
                abort_unless(
                    PaymentGate::passes($queueTicket->service),
                    402,
                    'Payment must be verified by Cashier/Billing Staff before this patient can be called.'
                );
            }

            if ($rule['to'] === 'IN_SERVICE') {
                $alreadyActive = QueueTicket::query()
                    ->whereHas('service', fn ($q) => $q->where('visit_id', $visit->id))
                    ->where('id', '!=', $queueTicket->id)
                    ->where('status', 'IN_SERVICE')
                    ->exists();

                if ($alreadyActive) {
                    // The Critical Rule short-circuits before the Payment
                    // Gate ever runs below, so a request that fails both at
                    // once would otherwise only ever surface the 409 —
                    // hiding an also-true payment problem from staff. This
                    // read-only PaymentGate::passes() call doesn't change
                    // the check order, it just lets the error body report
                    // both problems together.
                    throw new HttpResponseException(response()->json([
                        'message' => 'This patient already has an active service in another department.',
                        'payment_status' => PaymentGate::passes($queueTicket->service) ? 'verified' : 'unpaid',
                    ], 409));
                }

                // Payment Gate: Consultation, Laboratory, and Pharmacy all
                // require a verified payment before service starts —
                // PaymentGate::requiresPayment() is what set requires_payment
                // when this service was created, so this check stays in sync
                // with that by construction.
                abort_unless(
                    PaymentGate::passes($queueTicket->service),
                    402,
                    'Payment must be verified by Cashier/Billing Staff before this patient can be seen.'
                );
            }

            $fields = ['status' => $rule['to']];
            if ($rule['timestamp']) {
                $fields[$rule['timestamp']] = now();
            }
            $queueTicket->update($fields);

            if ($serviceStatus = self::SERVICE_STATUS[$rule['to']] ?? null) {
                $queueTicket->service->update(['status' => $serviceStatus]);
            }

            // A COMPLETED ticket doesn't necessarily mean the whole visit is
            // done — other services may still be pending elsewhere. Visit
            // closure is an explicit action (VisitController@complete).
            if ($rule['to'] !== 'COMPLETED') {
                $queueTicket->service->visit->update([
                    'overall_status' => QueueJourney::stateFor($queueTicket->service->department->dept_code, $rule['to']),
                ]);
            }

            // A cancelled / no-show service will never be paid for, so its
            // companion Billing-queue ticket (if any) is closed with it.
            if (in_array($rule['to'], ['CANCELLED', 'NO_SHOW'], true)) {
                BillingQueue::cancel($queueTicket->service, $actingUser);
            }

            QueueEvent::create([
                'queue_ticket_id' => $queueTicket->id,
                'event_type' => $rule['to'],
                'performed_by' => $actingUser->id,
                'event_time' => now(),
            ]);

            TicketStatusChanged::dispatch($queueTicket->fresh(['service.department', 'service.visit']));
        });

        return $queueTicket->fresh();
    }
}
