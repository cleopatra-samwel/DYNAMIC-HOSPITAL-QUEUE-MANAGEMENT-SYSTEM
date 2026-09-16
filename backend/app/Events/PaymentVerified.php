<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after PaymentController::verify() succeeds, so the requesting
 * department's own dashboard can immediately unblock its Payment Gate
 * error state (see TicketTransitionService's 402) without the user
 * manually retrying start-service until it happens to work.
 *
 * Private department channel only — payment/insurance details never
 * belong on a broadcast channel at all, staff-private or public, so this
 * carries nothing beyond "this service is now clear."
 */
class PaymentVerified implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public int $serviceId;

    public int $departmentId;

    public ?string $queueNumber;

    public function __construct(Payment $payment)
    {
        $payment->loadMissing('service.department', 'service.queueTicket');

        $this->serviceId = $payment->service_id;
        $this->departmentId = $payment->service->department_id;
        $this->queueNumber = $payment->service->queueTicket?->queue_number;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("department.{$this->departmentId}")];
    }

    public function broadcastAs(): string
    {
        return 'PaymentVerified';
    }

    public function broadcastWith(): array
    {
        return [
            'service_id' => $this->serviceId,
            'department_id' => $this->departmentId,
            'queue_number' => $this->queueNumber,
            'status' => 'VERIFIED',
        ];
    }
}
