<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QueueEvent;
use App\Models\QueueTicket;
use App\Models\ServiceRequestedTest;
use App\Services\TicketTransitionService;
use Illuminate\Http\Request;

/**
 * Queue ticket list + the status-lifecycle HTTP actions. The actual
 * lifecycle logic (transitions map, the Critical Rule's locked check,
 * role check, service-status sync, event logging) lives in
 * TicketTransitionService — extracted so it's directly callable from a
 * console command for genuine concurrency testing, not just from HTTP.
 */
class QueueTicketController extends Controller
{
    private const TICKET_RELATIONS = ['priorityLevel', 'service.department', 'service.visit.patient', 'service.previousService.department', 'service.previousService.queueTicket'];

    public function __construct(private readonly TicketTransitionService $transitions) {}

    public function index(Request $request)
    {
        $query = QueueTicket::query()->with(self::TICKET_RELATIONS)->latest();

        if ($departmentId = $request->query('department_id')) {
            $query->whereHas('service', fn ($q) => $q->where('department_id', $departmentId));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($priorityLevelId = $request->query('priority_level_id')) {
            $query->where('priority_level_id', $priorityLevelId);
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('queue_number', 'like', "%{$search}%")
                    ->orWhereHas('service.visit.patient', function ($p) use ($search) {
                        $p->where('name', 'like', "%{$search}%")->orWhere('patient_number', 'like', "%{$search}%");
                    });
            });
        }

        $tickets = $query->paginate(20);

        // Laboratory Role expansion — "Referring Doctor" and "Requested
        // Tests" per row, only computed when this fetch is scoped to the
        // Laboratory department (cheap, one extra pair of bulk queries;
        // every other department's fetch is completely unaffected).
        if ($departmentId && Department::find($departmentId)?->dept_code === 'LAB') {
            $this->attachLabExtras($tickets->getCollection());
        }

        return response()->json($tickets);
    }

    /**
     * A Laboratory service's own doctor_id is never set (see
     * VisitRegistrationService/ServiceFlowController — it's only ever an
     * optional Consultation "Preferred Doctor" pre-selection), so "who
     * actually treated this patient in Consultation" isn't a stored FK —
     * it's derived from the CALLED QueueEvent on the originating
     * Consultation ticket. Both this and the requested-tests checklist are
     * bulk-fetched here (not per-row) to avoid N+1 queries across the page.
     */
    private function attachLabExtras($labTickets): void
    {
        $originServiceIds = $labTickets->pluck('service.previous_service_id')->filter()->values();
        $originTicketIds = $labTickets->pluck('service.previousService.queueTicket.id')->filter()->values();

        $testNamesByServiceId = ServiceRequestedTest::whereIn('service_id', $originServiceIds)
            ->with('labTest:id,name')
            ->get()
            ->groupBy('service_id')
            ->map(fn ($rows) => $rows->pluck('labTest.name')->implode(', '));

        $doctorNameByTicketId = QueueEvent::whereIn('queue_ticket_id', $originTicketIds)
            ->where('event_type', 'CALLED')
            ->with('performedBy:id,first_name,last_name,name')
            ->orderByDesc('event_time')
            ->get()
            ->unique('queue_ticket_id')
            ->mapWithKeys(fn ($event) => [$event->queue_ticket_id => $event->performedBy?->name]);

        foreach ($labTickets as $ticket) {
            $originServiceId = $ticket->service?->previous_service_id;
            $originTicketId = $ticket->service?->previousService?->queueTicket?->id;

            $ticket->requested_test_names = $originServiceId ? ($testNamesByServiceId->get($originServiceId) ?: null) : null;
            $ticket->referring_doctor = $originTicketId ? $doctorNameByTicketId->get($originTicketId) : null;
        }
    }

    public function call(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'call', $request->user()));
    }

    public function startService(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'start-service', $request->user()));
    }

    /**
     * Optional `notes` — Laboratory's "Enter Results" and Pharmacy's "Mark
     * Medicine Dispensed" are both just this same completion action with a
     * free-text note attached to the service record (no separate
     * results/prescriptions tables exist in the Phase 5 schema).
     */
    public function complete(Request $request, QueueTicket $queueTicket)
    {
        $data = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        $ticket = $this->transitions->apply($queueTicket, 'complete', $request->user());

        if (array_key_exists('notes', $data) && $data['notes'] !== null) {
            $ticket->service->update(['notes' => $data['notes']]);
        }

        return $this->respond($ticket->fresh());
    }

    public function noShow(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'no-show', $request->user()));
    }

    public function cancel(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'cancel', $request->user()));
    }

    public function hold(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'hold', $request->user()));
    }

    public function transfer(Request $request, QueueTicket $queueTicket)
    {
        return $this->respond($this->transitions->apply($queueTicket, 'transfer', $request->user()));
    }

    private function respond(QueueTicket $ticket)
    {
        return response()->json(['ticket' => $ticket->load(self::TICKET_RELATIONS)]);
    }
}
