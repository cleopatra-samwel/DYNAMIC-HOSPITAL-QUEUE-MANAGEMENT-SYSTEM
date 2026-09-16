<?php

namespace Database\Seeders;

use App\Models\AudioClip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * PLACEHOLDER AUDIO ONLY. No real recordings exist yet; these are short
 * silent .wav files, one per label, purely so the announcement
 * sequencing/playback pipeline can be built and tested end-to-end now.
 *
 * Replace them with real recordings via `php artisan audio:import` (see
 * App\Console\Commands\ImportAudioClips) once the real voice folder is
 * available — upserts by label, so re-running either this seeder or that
 * command just swaps the file, nothing else in the codebase hardcodes a
 * path.
 *
 * Whole-number rework: the old digit_0-9 + dept_{code} + phrase_patient/
 * phrase_please_proceed_to scheme is gone (AnnouncementController no
 * longer spells digits or names a department by voice) — replaced by
 * number_1..number_1000, letter_a..letter_z (unchanged), and the 3
 * "others" clips (sfx_ring, phrase_ticket_number, phrase_counter_number).
 * Obsolete labels are deleted first so a reseed of an older database
 * doesn't leave dead rows sitting alongside the new scheme.
 *
 * Voice Announcements v2: phrase_ticket_number/phrase_counter_number are
 * retired from AnnouncementController's sequence (not deleted here, for
 * rollback) and superseded by SEG_LABELS below — phrase_floor has no
 * sequencing logic yet (see AnnouncementController) but is seeded anyway
 * so its placeholder exists ahead of that future phase.
 */
class AudioClipSeeder extends Seeder
{
    private const OTHER_LABELS = ['sfx_ring', 'phrase_ticket_number', 'phrase_counter_number'];

    private const SEG_LABELS = ['phrase_mwenye_kadi_namba', 'phrase_please_proceed', 'phrase_room_number', 'phrase_window_number', 'phrase_doctor', 'phrase_floor'];

    public function run(): void
    {
        $this->deleteObsoleteLabels();

        $labels = [];

        foreach (range(1, 1000) as $number) {
            $labels[] = "number_{$number}";
        }

        foreach (range('a', 'z') as $letter) {
            $labels[] = "letter_{$letter}";
        }

        foreach (self::OTHER_LABELS as $label) {
            $labels[] = $label;
        }

        foreach (self::SEG_LABELS as $label) {
            $labels[] = $label;
        }

        foreach ($labels as $label) {
            $path = "audio-clips/{$label}.wav";
            Storage::disk('public')->put($path, $this->silentWavBytes());

            AudioClip::updateOrCreate(
                ['label' => $label],
                ['file_path' => $path, 'language' => 'sw']
            );
        }
    }

    private function deleteObsoleteLabels(): void
    {
        $obsolete = AudioClip::where('label', 'like', 'digit\_%')
            ->orWhere('label', 'like', 'dept\_%')
            ->orWhereIn('label', ['phrase_patient', 'phrase_please_proceed_to'])
            ->get();

        foreach ($obsolete as $clip) {
            Storage::disk('public')->delete($clip->file_path);
            $clip->delete();
        }
    }

    /** A minimal valid 8-bit PCM mono WAV, ~0.3s of silence (128 = midpoint for unsigned 8-bit PCM, not 0). */
    private function silentWavBytes(float $seconds = 0.3, int $sampleRate = 8000): string
    {
        $numSamples = (int) ($seconds * $sampleRate);
        $dataSize = $numSamples;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', $sampleRate)
            .pack('V', $sampleRate)
            .pack('v', 1)
            .pack('v', 8)
            .'data'
            .pack('V', $dataSize);

        return $header.str_repeat(chr(128), $dataSize);
    }
}
