<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueEvent;

/**
 * Backs the read-only Notification list. There's no dedicated notifications
 * table yet — this reformats real queue_events (ticket created/called/
 * completed) into a simple feed, rather than inventing a separate
 * notifications subsystem for this phase.
 */
class QueueEventController extends Controller
{
    private const MESSAGES = [
        'CREATED' => 'New ticket :ticket created for :patient (:department)',
        'CALLED' => 'Ticket :ticket for :patient has been called',
        'IN_SERVICE' => 'Ticket :ticket for :patient is now in service',
        'COMPLETED' => 'Ticket :ticket for :patient marked complete',
        'NO_SHOW' => 'Ticket :ticket for :patient marked as no-show',
        'CANCELLED' => 'Ticket :ticket for :patient was cancelled',
        'ON_HOLD' => 'Ticket :ticket for :patient put on hold',
        'TRANSFERRED' => 'Ticket :ticket for :patient marked for transfer',
        'HOLD_AUTO_RESOLVED' => 'Held service for :patient auto-resolved — :notes',
        'HOLD_RESOLVED' => 'Held service for :patient resolved by staff — :notes',
    ];

    public function recent()
    {
        $events = QueueEvent::query()
            ->with(['queueTicket.service.department', 'queueTicket.service.visit.patient', 'performedBy'])
            ->latest('event_time')
            ->limit(30)
            ->get();

        $notifications = $events->map(function (QueueEvent $event) {
            $ticket = $event->queueTicket;
            $patient = $ticket?->service?->visit?->patient;
            $department = $ticket?->service?->department;

            $template = self::MESSAGES[$event->event_type] ?? ':ticket: :event_type';

            $message = strtr($template, [
                ':ticket' => $ticket?->queue_number ?? '—',
                ':patient' => $patient?->name ?? 'Unknown patient',
                ':department' => $department?->dept_name ?? 'Unknown department',
                ':event_type' => $event->event_type,
                ':notes' => $event->notes ?? '',
            ]);

            return [
                'id' => $event->id,
                'message' => $message,
                'performed_by' => $event->performedBy?->fullName(),
                'event_time' => $event->event_time,
            ];
        });

        return response()->json(['notifications' => $notifications]);
    }
}
