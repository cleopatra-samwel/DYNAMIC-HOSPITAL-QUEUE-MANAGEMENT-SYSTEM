<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\Visit;
use App\Rules\ActiveDoctor;
use App\Services\VisitRegistrationService;
use App\Support\QrCodeService;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    private const VISIT_RELATIONS = ['patient', 'services.department', 'services.doctor', 'services.queueTicket.priorityLevel'];

    public function index(Request $request)
    {
        $query = Visit::query()->with(self::VISIT_RELATIONS)->latest();

        // Backs the Patients page's "View Visits" modal — one patient's
        // full visit history, via the same listing endpoint/relations
        // every other visit list already uses, not a second query built
        // separately.
        if ($patientId = $request->query('patient_id')) {
            $query->where('patient_id', $patientId);
        }

        if ($patientType = $request->query('patient_type')) {
            $query->where('patient_type', $patientType);
        }

        if ($date = $request->query('date')) {
            $query->whereDate('visit_date', $date);
        }

        if ($search = $request->query('q')) {
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('patient_number', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Creates a visit for an existing patient, plus the first Service +
     * QueueTicket for the requested department, all in one transaction.
     * This is the minimum viable ticket generation for Phase 2 — no aging
     * or call-next logic yet, that's a later phase.
     */
    public function store(Request $request, VisitRegistrationService $registrationService)
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'patient_type' => ['required', 'in:Normal,Emergency'],
            'payment_method' => ['required', 'in:Cash,Insurance'],
            'insurance_provider' => ['required_if:payment_method,Insurance', 'nullable', 'string', 'max:255'],
            'insurance_ref' => ['required_if:payment_method,Insurance', 'nullable', 'string', 'max:255'],
            'department_id' => ['required', 'exists:departments,id'],
            'doctor_id' => ['nullable', 'integer', new ActiveDoctor()],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
        ]);

        $patient = Patient::findOrFail($data['patient_id']);
        $visit = $registrationService->createVisit($patient, $data, $request->user());

        return response()->json([
            'visit' => $visit->load(self::VISIT_RELATIONS),
            'qr_code' => QrCodeService::trackingQrDataUri($visit),
        ], 201);
    }

    /**
     * Registration Staff confirms or corrects the placeholder patient_type
     * ("Normal") and payment_method ("Cash") a self check-in was
     * auto-created with (see CheckInController::store) — done once this
     * visit's Registration ticket is called, before forwarding to the
     * patient's actual target department (ServiceFlowController::store).
     * Only valid while the visit is still sitting at Registration — once
     * forwarded, these fields are locked in for billing/priority purposes.
     */
    public function updateRegistrationDetails(Request $request, Visit $visit)
    {
        abort_unless(
            $request->user()->hasAnyRole(['Registration Staff', 'Administrator']),
            403,
            'Only Registration Staff may update registration details.'
        );

        $currentDeptCode = $visit->services()->latest('id')->first()?->department?->dept_code;
        abort_unless($currentDeptCode === 'REG', 422, 'This visit is no longer at Registration.');

        $data = $request->validate([
            'patient_type' => ['required', 'in:Normal,Emergency'],
            'payment_method' => ['required', 'in:Cash,Insurance'],
            'insurance_provider' => ['required_if:payment_method,Insurance', 'nullable', 'string', 'max:255'],
            'insurance_ref' => ['required_if:payment_method,Insurance', 'nullable', 'string', 'max:255'],
        ]);

        $visit->update([
            'patient_type' => $data['patient_type'],
            'payment_method' => $data['payment_method'],
            'insurance_provider' => $data['insurance_provider'] ?? null,
            'insurance_ref' => $data['insurance_ref'] ?? null,
        ]);

        return response()->json(['visit' => $visit->fresh()->load(self::VISIT_RELATIONS)]);
    }

    /**
     * Doctor-only: clinically confirms an Emergency classification. Enforced
     * by the `role:Doctor` middleware on the route, not just hidden in the UI.
     */
    public function confirmEmergency(Visit $visit)
    {
        abort_if($visit->patient_type !== 'Emergency', 422, 'This visit is not classified as Emergency.');

        $visit->update(['emergency_confirmed' => true]);

        return response()->json(['visit' => $visit->load(self::VISIT_RELATIONS)]);
    }

    /**
     * Doctor-only: the clinical review determined this was not actually an
     * emergency, so it's downgraded back to Normal.
     */
    public function downgradeEmergency(Visit $visit)
    {
        abort_if($visit->patient_type !== 'Emergency', 422, 'This visit is not classified as Emergency.');

        $visit->update(['patient_type' => 'Normal', 'emergency_confirmed' => false]);

        return response()->json(['visit' => $visit->load(self::VISIT_RELATIONS)]);
    }

    public function statsToday()
    {
        $today = now()->toDateString();
        $base = Visit::whereDate('visit_date', $today);

        return response()->json([
            'emergency' => (clone $base)->where('patient_type', 'Emergency')->count(),
            'normal' => (clone $base)->where('patient_type', 'Normal')->count(),
            'total' => (clone $base)->count(),
            // Live Registration queue length (not limited to today's visits).
            'waiting' => QueueTicket::where('status', 'WAITING')
                ->whereHas('service.department', fn ($q) => $q->where('dept_code', 'REG'))
                ->count(),
        ]);
    }

    /**
     * Explicit visit closure — only succeeds once every one of the visit's
     * services is Completed or Cancelled (none left Pending, Active, or
     * OnHold). A ticket reaching COMPLETED no longer sets this by itself
     * (see QueueTicketController::applyTransition), precisely so a visit
     * with more steps still ahead can't be closed early by accident.
     */
    public function complete(Visit $visit)
    {
        $openServices = Service::where('visit_id', $visit->id)
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->count();

        abort_if(
            $openServices > 0,
            422,
            "Cannot complete this visit — {$openServices} service(s) are still Pending, Active, or OnHold."
        );

        $visit->update(['overall_status' => 'COMPLETED']);

        return response()->json(['visit' => $visit->fresh()->load(self::VISIT_RELATIONS)]);
    }
}
