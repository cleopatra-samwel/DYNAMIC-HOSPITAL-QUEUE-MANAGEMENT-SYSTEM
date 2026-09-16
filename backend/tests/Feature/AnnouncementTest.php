<?php

namespace Tests\Feature;

use App\Models\AudioClip;
use App\Models\Department;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Voice Announcements v2 — DoD #1/#2: calling a real test ticket plays
 * ring -> "Mwenye kadi namba" -> correct letter -> correct whole number ->
 * "Tafadhali elekea" -> "Chumba namba"/"Dirisha namba" (per department's
 * location_phrase_type) -> correct counter number -> "Daktari"
 * (Consultation only), verified
 * against the actual returned clip LABELS (not just "it plays
 * something"), and no patient-name clip anywhere in it (unchanged
 * privacy rule from Phase 8).
 *
 * Uses Storage::fake('public') throughout — without it, "a clip's file
 * really exists" assertions would depend on whatever happens to be
 * physically seeded in this dev environment's real storage/app/public
 * directory rather than on anything the test itself controls.
 */
class AnnouncementTest extends NotificationTestCase
{
    private const FORBIDDEN_LABEL_SUBSTRINGS = ['name', 'patient_name', 'first_name', 'last_name'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_consultation_announcement_uses_room_wording_with_doctor_phrase(): void
    {
        $this->cons->update(['counter_number' => 2, 'location_phrase_type' => 'room', 'append_doctor_phrase' => true]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $ticket->update(['queue_number' => 'CONS-0024']);

        // Public — no auth header at all, same as the kiosk page that calls this.
        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);

        $labels = collect($response->json('sequence'))->pluck('label');

        foreach ($labels as $label) {
            foreach (self::FORBIDDEN_LABEL_SUBSTRINGS as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $label, "Label '{$label}' must never reference patient name.");
            }
        }

        $this->assertSame([
            'sfx_ring',
            'phrase_mwenye_kadi_namba',
            'letter_c',
            'number_24',
            'phrase_please_proceed',
            'phrase_room_number',
            'number_2',
            'phrase_doctor',
        ], $labels->all());
    }

    public function test_laboratory_announcement_uses_room_wording_without_doctor_phrase(): void
    {
        $this->lab->update(['counter_number' => 3, 'location_phrase_type' => 'room', 'append_doctor_phrase' => false]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->lab);
        $ticket->update(['queue_number' => 'LAB-0007']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
        $labels = collect($response->json('sequence'))->pluck('label');

        $this->assertSame([
            'sfx_ring',
            'phrase_mwenye_kadi_namba',
            'letter_l',
            'number_7',
            'phrase_please_proceed',
            'phrase_room_number',
            'number_3',
        ], $labels->all());
    }

    public function test_registration_announcement_uses_window_wording_without_doctor_phrase(): void
    {
        $registration = Department::create(['dept_code' => 'REG', 'dept_name' => 'Registration', 'is_active' => true, 'counter_number' => 1, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($registration);
        $ticket->update(['queue_number' => 'REG-0009']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
        $labels = collect($response->json('sequence'))->pluck('label');

        $this->assertSame([
            'sfx_ring',
            'phrase_mwenye_kadi_namba',
            'letter_r',
            'number_9',
            'phrase_please_proceed',
            'phrase_window_number',
            'number_1',
        ], $labels->all());
    }

    public function test_pharmacy_announcement_uses_window_wording_without_doctor_phrase(): void
    {
        $pharmacy = Department::create(['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'is_active' => true, 'counter_number' => 4, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($pharmacy);
        $ticket->update(['queue_number' => 'PHARM-0009']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
        $labels = collect($response->json('sequence'))->pluck('label');

        $this->assertSame([
            'sfx_ring',
            'phrase_mwenye_kadi_namba',
            'letter_p',
            'number_9',
            'phrase_please_proceed',
            'phrase_window_number',
            'number_4',
        ], $labels->all());
    }

    public function test_billing_announcement_uses_window_wording_without_doctor_phrase(): void
    {
        $billing = Department::create(['dept_code' => 'BILL', 'dept_name' => 'Billing', 'is_active' => true, 'counter_number' => 5, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($billing);
        $ticket->update(['queue_number' => 'BILL-0005']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
        $labels = collect($response->json('sequence'))->pluck('label');

        $this->assertSame([
            'sfx_ring',
            'phrase_mwenye_kadi_namba',
            'letter_b',
            'number_5',
            'phrase_please_proceed',
            'phrase_window_number',
            'number_5',
        ], $labels->all());
    }

    public function test_announcement_endpoint_requires_no_authentication(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);

        // No actingAs() at all.
        $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
    }

    public function test_announcement_gracefully_falls_back_when_a_label_has_no_uploaded_clip(): void
    {
        $this->cons->update(['counter_number' => 2, 'location_phrase_type' => 'room', 'append_doctor_phrase' => true]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $ticket->update(['queue_number' => 'CONS-0001']);

        // Only ONE of the labels this ticket needs has both a DB row AND a
        // real file behind it — the others (never seeded in this test's
        // fresh DB, no row at all) must still appear in the sequence with
        // url: null, not break it. Scenario A: no AudioClip row at all.
        Storage::disk('public')->put('audio-clips/letter_c.wav', 'fake wav bytes');
        AudioClip::create(['label' => 'letter_c', 'file_path' => 'audio-clips/letter_c.wav', 'language' => 'sw']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);
        $sequence = collect($response->json('sequence'));

        $withClip = $sequence->firstWhere('label', 'letter_c');
        $this->assertNotNull($withClip['url'], 'A label with an uploaded clip must resolve to a real URL.');

        $withoutClip = $sequence->firstWhere('label', 'sfx_ring');
        $this->assertNotNull($withoutClip, 'The label must still appear in the sequence...');
        $this->assertNull($withoutClip['url'], '...but with a null url when nothing has been uploaded for it yet.');

        // phrase_doctor has no recording on disk anywhere in this repo yet
        // (see ImportAudioClips) — it must still degrade gracefully, same
        // as any other unfulfilled label.
        $doctorPhrase = $sequence->firstWhere('label', 'phrase_doctor');
        $this->assertNotNull($doctorPhrase, 'phrase_doctor must still appear in the sequence for a department with append_doctor_phrase...');
        $this->assertNull($doctorPhrase['url'], '...but with a null url until a real clip is uploaded for it.');
    }

    /**
     * A numeric part beyond the real 1-1000 clip range must degrade
     * gracefully (null url), not crash the request — the same
     * missing-clip fallback already covers this, no extra code needed.
     */
    public function test_a_numeric_part_beyond_1000_falls_back_to_a_null_url_instead_of_crashing(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $ticket->update(['queue_number' => 'CONS-1001']);

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);

        $entry = collect($response->json('sequence'))->firstWhere('label', 'number_1001');
        $this->assertNotNull($entry, 'The out-of-range label must still appear in the sequence...');
        $this->assertNull($entry['url'], '...with a null url, same as any other unfulfilled label.');
    }

    /**
     * Scenario B, distinct from scenario A above: an AudioClip DB row
     * exists, but the file it points to is actually missing from disk
     * (deleted by hand, a bad upload, a stale seed). Before this fix,
     * Storage::url() doesn't check existence — it's pure string
     * concatenation — so the controller returned a dead-link URL here
     * instead of null, confirmed live against the real running server
     * before this test was written.
     */
    public function test_announcement_nulls_the_url_when_a_clip_row_exists_but_its_file_is_missing_from_disk(): void
    {
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $ticket->update(['queue_number' => 'CONS-0007']);

        // Row exists, but nothing was ever actually put at that path in
        // fake storage — simulates a deleted/never-written file.
        AudioClip::create(['label' => 'number_7', 'file_path' => 'audio-clips/number_7.wav', 'language' => 'sw']);
        Storage::disk('public')->assertMissing('audio-clips/number_7.wav');

        Log::spy();

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);

        $entry = collect($response->json('sequence'))->firstWhere('label', 'number_7');
        $this->assertNotNull($entry, 'The label must still appear in the sequence...');
        $this->assertNull($entry['url'], '...but with a null url, matching the "no clip uploaded" case from the caller\'s perspective, since a dead link is just as useless.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'missing')
                && $context['label'] === 'number_7'
                && $context['file_path'] === 'audio-clips/number_7.wav'
            );
    }

    /** A ticket-number and a counter-number can coincide (e.g. ticket #2 at Counter 2) — the same "number_2" label repeating must not double-log the missing-file warning. */
    public function test_missing_file_warning_is_logged_only_once_even_when_the_label_repeats(): void
    {
        $this->cons->update(['counter_number' => 2, 'location_phrase_type' => 'room', 'append_doctor_phrase' => false]);
        [, $ticket] = $this->makeWaitingVisitAndTicket($this->cons);
        $ticket->update(['queue_number' => 'CONS-0002']);

        AudioClip::create(['label' => 'number_2', 'file_path' => 'audio-clips/number_2.wav', 'language' => 'sw']);

        Log::spy();

        $response = $this->getJson("/api/queue-tickets/{$ticket->id}/announcement")->assertStatus(200);

        $labels = collect($response->json('sequence'))->pluck('label');
        $this->assertSame(2, $labels->filter(fn ($l) => $l === 'number_2')->count(), 'Sanity check: this scenario really does repeat the label.');

        Log::shouldHaveReceived('warning')->once();
    }
}
