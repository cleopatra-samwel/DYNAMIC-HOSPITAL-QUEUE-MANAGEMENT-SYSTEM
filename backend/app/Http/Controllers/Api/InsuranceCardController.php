<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCard;
use App\Models\Visit;
use Illuminate\Http\Request;

/**
 * Phase 6 — insurance card custody belongs to Registration Staff only,
 * from intake to return, at the same counter the patient registered at.
 * Cashier/Billing Staff never touches a card — deliberately excluded from
 * assertRegistrationStaff below, not just omitted by oversight.
 */
class InsuranceCardController extends Controller
{
    private function assertRegistrationStaff(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(['Registration Staff', 'Administrator']),
            403,
            'Only Registration Staff may handle insurance cards.'
        );
    }

    public function receive(Request $request, Visit $visit)
    {
        $this->assertRegistrationStaff($request);

        abort_unless(
            $visit->payment_method === 'Insurance',
            422,
            'This visit is not an Insurance visit — there is no card to receive.'
        );

        $existing = InsuranceCard::where('visit_id', $visit->id)->first();
        abort_if(
            $existing && $existing->received_at !== null,
            422,
            'This visit\'s insurance card has already been received.'
        );

        $card = InsuranceCard::updateOrCreate(
            ['visit_id' => $visit->id],
            ['received_by' => $request->user()->id, 'received_at' => now()]
        );

        return response()->json(['insurance_card' => $card->fresh()->load(['receivedBy', 'visit.patient'])], 201);
    }

    public function release(Request $request, Visit $visit)
    {
        $this->assertRegistrationStaff($request);

        $data = $request->validate(['signoff_confirmation' => ['required', 'string', 'max:255']]);

        abort_unless(
            $visit->overall_status === 'COMPLETED',
            422,
            'The card can only be released once the visit is COMPLETED.'
        );

        $card = InsuranceCard::where('visit_id', $visit->id)->first();
        abort_if($card === null || $card->received_at === null, 422, 'No insurance card is on record as received for this visit.');
        abort_if($card->released_at !== null, 422, 'This insurance card has already been released.');

        $card->update([
            'released_by' => $request->user()->id,
            'released_at' => now(),
            'signoff_confirmation' => $data['signoff_confirmation'],
        ]);

        return response()->json(['insurance_card' => $card->fresh()->load(['receivedBy', 'releasedBy', 'visit.patient'])]);
    }

    /** Three tabs for Registration's Insurance Cards page: pending receipt, in custody, released history. */
    public function index(Request $request)
    {
        $this->assertRegistrationStaff($request);

        $status = $request->query('status', 'in_custody');

        $query = InsuranceCard::query()->with(['visit.patient', 'receivedBy', 'releasedBy'])->latest();
        $query = $status === 'released'
            ? $query->whereNotNull('released_at')
            : $query->whereNotNull('received_at')->whereNull('released_at');

        return response()->json(['insurance_cards' => $query->get()]);
    }

    /**
     * Insurance visits registered but not yet confirmed as physically
     * received — receiving is a deliberate staff action (see `receive()`
     * above), never automatic on visit creation, so this is the normal
     * resting state right after registering an Insurance patient until
     * someone explicitly confirms the card is in hand.
     */
    public function pendingReceipt(Request $request)
    {
        $this->assertRegistrationStaff($request);

        $visits = Visit::query()
            ->where('payment_method', 'Insurance')
            ->whereDoesntHave('insuranceCard')
            ->with('patient')
            ->latest()
            ->get()
            ->map(fn (Visit $visit) => [
                'visit_id' => $visit->id,
                'patient_name' => $visit->patient?->name,
                'patient_number' => $visit->patient?->patient_number,
                'insurance_provider' => $visit->insurance_provider,
                'insurance_ref' => $visit->insurance_ref,
                'created_at' => $visit->created_at,
            ]);

        return response()->json(['pending_receipt' => $visits]);
    }
}
