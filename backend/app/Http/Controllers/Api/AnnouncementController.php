<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudioClip;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Voice Announcements v2 — public (no auth), same as /api/track and
 * /api/waiting-display: the waiting-area kiosk page that calls this has no
 * login either. The ticket id here is not a new leak — it's already
 * broadcast on the public waiting-display.{departmentId} channel's
 * TicketCalled payload, which is exactly how the kiosk page learns which
 * ticket to ask about.
 *
 * Sequence is built entirely from queue_number + department's
 * counter_number/location_phrase_type/append_doctor_phrase — never a
 * "patient name" clip, there is no such label and no code path that could
 * introduce one (see the label list below).
 *
 * queue_number is "{DEPT_CODE}-{0007}" (e.g. "CONS-0024", see
 * QueueNumberGenerator) — dept_code is a multi-letter code, not a single
 * letter, so only its FIRST character is spoken as the letter clip (e.g.
 * "C" for CONS). All 5 real department codes (REG/CONS/LAB/PHARM/BILL)
 * have distinct first letters, so this stays unambiguous.
 *
 * v1's phrase_ticket_number/phrase_counter_number are retired from this
 * sequence (superseded by phrase_please_proceed + phrase_room_number/
 * phrase_window_number) but their AudioClip rows/files are left in place
 * for rollback — see ImportAudioClips.
 *
 * Order: sfx_ring, then phrase_mwenye_kadi_namba (the "ticket number
 * holder is..." lead-in), then the ticket's own letter+number, THEN
 * phrase_please_proceed and the room/window number — the lead-in phrase
 * introduces that a number is about to be read, the number identifies who
 * this announcement is for, then it says where to go.
 */
class AnnouncementController extends Controller
{
    public function show(QueueTicket $queueTicket)
    {
        $queueTicket->loadMissing('service.department');
        $department = $queueTicket->service->department;

        $labels = ['sfx_ring', 'phrase_mwenye_kadi_namba'];

        if (preg_match('/^([A-Za-z]+)-0*(\d+)$/', $queueTicket->queue_number, $matches)) {
            $labels[] = 'letter_'.strtolower($matches[1][0]);
            // Whole-number clips run 1-1000 (see AudioClipSeeder/audio:import) —
            // a numeric part outside that range simply has no matching
            // AudioClip row, which the existing missing-clip -> null ->
            // frontend-skips behavior below already handles gracefully.
            $labels[] = 'number_'.((int) $matches[2]);
        }

        // Ticket number is spoken FIRST, "please proceed to..." after it —
        // "C, 24, please proceed to window 2", not "please proceed, C, 24,
        // window 2". Matches how staff actually expect to hear it: the
        // number that identifies which patient this is for, then where to
        // go.
        $labels[] = 'phrase_please_proceed';

        $labels[] = $department->location_phrase_type === 'window' ? 'phrase_window_number' : 'phrase_room_number';
        if ($department->counter_number !== null) {
            $labels[] = 'number_'.$department->counter_number;
        }

        if ($department->append_doctor_phrase) {
            $labels[] = 'phrase_doctor';
        }

        $clipsByLabel = AudioClip::whereIn('label', $labels)->get()->keyBy('label');
        // A queue_number can repeat a character (e.g. "CONS-0007" has three
        // "0"s), so the same label can appear more than once in $labels —
        // memoize the existence check per unique label so a request never
        // stats the same file twice or logs the same warning twice.
        $existsByLabel = [];

        $sequence = collect($labels)->map(function (string $label) use ($clipsByLabel, &$existsByLabel) {
            $clip = $clipsByLabel->get($label);

            // Two distinct gaps, both resolving to url: null so the
            // frontend skips the clip either way, but only one of them is
            // silent-by-design: no AudioClip row at all just means nobody
            // has uploaded that label yet (expected, e.g. fresh
            // placeholders not yet replaced). A row that exists but whose
            // file is actually missing from disk (deleted by hand, a bad
            // upload) is a real gap that would otherwise go unnoticed
            // until someone happens to listen for it — logged as a
            // warning so it surfaces, and see the /api/audio-clips/health-check
            // endpoint for auditing this across every clip, not just the
            // ones a live announcement happens to touch.
            if (! $clip) {
                return ['label' => $label, 'url' => null];
            }

            if (! array_key_exists($label, $existsByLabel)) {
                $existsByLabel[$label] = Storage::disk('public')->exists($clip->file_path);

                if (! $existsByLabel[$label]) {
                    Log::warning('Audio clip file missing from disk', [
                        'label' => $clip->label,
                        'file_path' => $clip->file_path,
                    ]);
                }
            }

            return [
                'label' => $label,
                'url' => $existsByLabel[$label] ? Storage::disk('public')->url($clip->file_path) : null,
            ];
        });

        return response()->json([
            'ticket_id' => $queueTicket->id,
            'queue_number' => $queueTicket->queue_number,
            'sequence' => $sequence,
        ]);
    }
}
