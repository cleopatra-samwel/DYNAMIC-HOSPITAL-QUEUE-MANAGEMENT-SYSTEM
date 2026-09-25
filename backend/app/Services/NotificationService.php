<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Notification;
use App\Models\QueueTicket;
use App\Models\Visit;
use App\Support\QrCodeService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8. Despite the spec describing this as "a stub that already
 * exists from Phase 2", no such class existed anywhere in the codebase —
 * this is a from-scratch implementation, not an extension.
 *
 * Patient-facing notify*() methods are all scoped to one visit — the
 * message text only ever describes that visit's own ticket, never
 * anything about another patient (there is no cross-visit data available
 * to leak here in the first place, by construction).
 */
class NotificationService
{
    public function sendTrackingLink(Visit $visit): void
    {
        $visit->loadMissing(['patient', 'services.department', 'services.queueTicket']);
        $url = QrCodeService::trackingUrl($visit);
        $contact = $visit->patient?->contact;

        // The visit's first (only, at this point) service/ticket — every
        // caller of this method (VisitRegistrationService) invokes it
        // immediately after creating exactly one.
        $service = $visit->services->first();
        $queueNumber = $service?->queueTicket?->queue_number ?? '—';
        $counterNumber = $service?->department?->counter_number ?? '—';

        $message = "Ticket number {$queueNumber}. Please proceed to Counter number {$counterNumber} when called. Track your status: {$url}";

        $this->sendSms($contact, $message, 'Would send tracking link');
    }

    public function notifyWaiting(QueueTicket $ticket): Notification
    {
        $ticket->loadMissing('service.department');
        $department = $ticket->service->department;

        return $this->createForVisit($ticket, 'WAITING', "You are now waiting in {$department->dept_name}. Your queue number is {$ticket->queue_number}.");
    }

    public function notifyApproaching(QueueTicket $ticket): Notification
    {
        $ticket->loadMissing('service.department');
        $department = $ticket->service->department;

        return $this->createForVisit($ticket, 'APPROACHING', "You're almost up in {$department->dept_name} — queue number {$ticket->queue_number} will be called soon.");
    }

    public function notifyCalled(QueueTicket $ticket): Notification
    {
        $ticket->loadMissing('service.department');
        $department = $ticket->service->department;

        return $this->createForVisit($ticket, 'CALLED', "You are being called now — please proceed to {$department->dept_name}. Queue number {$ticket->queue_number}.");
    }

    public function notifyTransferred(QueueTicket $newTicket, Department $fromDepartment): Notification
    {
        $newTicket->loadMissing('service.department', 'service.visit.patient');
        $service = $newTicket->service;
        $toDepartment = $service->department;
        $visit = $service->visit;

        $notification = $this->createForVisit($newTicket, 'TRANSFERRED', "You've been transferred from {$fromDepartment->dept_name} to {$toDepartment->dept_name}. Your new queue number is {$newTicket->queue_number}.");

        // Same tracking link as at registration — it already reflects the
        // new department/ticket, since TrackingController always reads the
        // visit's LATEST service. Re-sent here so the patient doesn't have
        // to rely on remembering the original message when they're
        // physically moving to a new counter.
        $url = QrCodeService::trackingUrl($visit);
        $message = "You've been transferred to {$toDepartment->dept_name}. Ticket number {$newTicket->queue_number}. Please proceed to Counter number {$toDepartment->counter_number} when called. Track your status: {$url}";
        $this->sendSms($visit->patient?->contact, $message, 'Would send transfer notice');

        return $notification;
    }

    public function notifyCompleted(QueueTicket $ticket): Notification
    {
        $ticket->loadMissing('service.department');
        $department = $ticket->service->department;

        return $this->createForVisit($ticket, 'COMPLETED', "Your visit to {$department->dept_name} is complete.");
    }

    /** Staff-facing, not patient-facing — no visit_id, so it never appears on the public tracking endpoint. */
    public function alertLongWait(QueueTicket $ticket, Department $department, int $waitingMinutes, int $thresholdMinutes): Notification
    {
        $ticket->loadMissing('priorityLevel');

        return Notification::create([
            'type' => 'LONG_WAIT_ALERT',
            'message' => "Ticket {$ticket->queue_number} has waited {$waitingMinutes} min in {$department->dept_name} ({$ticket->priorityLevel->name} priority, threshold {$thresholdMinutes} min).",
            'queue_ticket_id' => $ticket->id,
            'department_id' => $department->id,
        ]);
    }

    /** Staff-facing: a patient has waited past the expected time without being called. Same no-visit_id rule as alertLongWait. */
    public function alertOverdueWait(QueueTicket $ticket, Department $department, int $waitingMinutes, int $expectedMinutes): Notification
    {
        return Notification::create([
            'type' => 'WAIT_OVERDUE',
            'message' => "Patient {$ticket->queue_number} has waited {$waitingMinutes} min in {$department->dept_name} without being called — longer than the expected {$expectedMinutes} min.",
            'queue_ticket_id' => $ticket->id,
            'department_id' => $department->id,
        ]);
    }

    // No phone format validation exists yet — the real gateway integration
    // must add this, since malformed numbers currently pass through
    // untouched all the way to the delivery attempt. Deliberately
    // deferred: format requirements differ by provider (E.164 vs local
    // format), so validating now risks encoding the wrong provider's
    // rules before one is actually chosen.
    // TODO: replace this log with a real SMS gateway call once
    // credentials are available — e.g. for Africa's Talking:
    // Http::asForm()->post('https://api.africastalking.com/version1/messaging', [
    //     'username' => config('services.africastalking.username'),
    //     'to' => $contact,
    //     'message' => $message,
    // ])->throw();
    private function sendSms(?string $contact, string $message, string $label): void
    {
        Log::info("[SMS stub] {$label}", [
            'to' => $contact,
            'message' => $message,
        ]);
    }

    private function createForVisit(QueueTicket $ticket, string $type, string $message): Notification
    {
        return Notification::create([
            'type' => $type,
            'message' => $message,
            'visit_id' => $ticket->service->visit_id,
            'queue_ticket_id' => $ticket->id,
            'department_id' => $ticket->service->department_id,
        ]);
    }
}
