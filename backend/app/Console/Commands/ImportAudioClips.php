<?php

namespace App\Console\Commands;

use App\Models\AudioClip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-imports the real voice recordings (numbers 1-1000, letters A-Z,
 * the v1 ring/ticket/counter phrase clips, and the v2 seg_1/seg_2
 * location-aware phrase clips) — the one-time replacement for
 * AudioClipSeeder's silent placeholders. Safe to re-run: every import
 * upserts by label, same convention as AudioClipController::store and
 * AudioClipSeeder, so nothing else in the codebase needs to know this
 * command ran.
 *
 * Expects {path}/numbers/*.mp3, {path}/letters/*.mp3, {path}/seg_1/*.mp3,
 * {path}/seg_2/*.mp3, and either {path}/others/*.mp3 or {path}/other/*.mp3
 * (both spellings accepted — a real voice folder handed over used the
 * singular; others/other is the retired v1 ring/ticket/counter set, kept
 * importable for rollback) — resolved relative to the Laravel project
 * root (base_path()), so the default `voice` argument means a `voice/`
 * folder sitting next to `app/`, `routes/`, etc.
 */
class ImportAudioClips extends Command
{
    protected $signature = 'audio:import {path=voice : Folder (relative to the project root) containing numbers/, letters/, seg_1/, seg_2/, and others/ subfolders}';

    protected $description = 'Import real voice recordings (numbers, letters, phrases/sfx) into audio_clips, replacing the silent placeholders.';

    /**
     * Keyword-matched against each "others" filename (lowercased) since
     * the real filenames weren't available to hardcode exactly — the
     * command reports any file it can't confidently match instead of
     * guessing wrong, so a naming mismatch is caught here, not discovered
     * later as a silent gap in a live announcement.
     */
    private const OTHERS_KEYWORDS = [
        'sfx_ring' => ['ring', 'bell', 'kengele', 'mlio', 'sauti'],
        'phrase_ticket_number' => ['ticket', 'tiketi'],
        'phrase_counter_number' => ['counter', 'kaunta', 'dirisha'],
    ];

    /**
     * v2 seg_1 — the ring + "mwenye kadi namba" lead-in + "please proceed"
     * opener, spoken for every department regardless of room/window
     * wording.
     */
    private const SEG_1_KEYWORDS = [
        'sfx_ring' => ['ring', 'bell', 'kengele', 'mlio', 'sauti'],
        'phrase_mwenye_kadi_namba' => ['mwenye', 'kadi', 'nambari'],
        'phrase_please_proceed' => ['tafadhali', 'elekea', 'proceed'],
    ];

    /**
     * v2 seg_2 — location vocabulary, mixed and matched per department
     * (see Department::location_phrase_type / append_doctor_phrase).
     * phrase_doctor and phrase_floor have no recording on disk yet as of
     * this command's introduction — the keywords are defined ahead of
     * the files so a future re-run picks them up with no code change.
     */
    private const SEG_2_KEYWORDS = [
        'phrase_room_number' => ['chumba'],
        'phrase_window_number' => ['dirisha'],
        'phrase_doctor' => ['daktari', 'doctor'],
        'phrase_floor' => ['floor', 'ghorofa'],
    ];

    public function handle(): int
    {
        $basePath = base_path($this->argument('path'));

        if (! is_dir($basePath)) {
            $this->error("Path not found: {$basePath}");

            return self::FAILURE;
        }

        $unmatched = [];

        $foundNumbers = [];
        $numbersImported = $this->importNumbers("{$basePath}/numbers", $unmatched, $foundNumbers);
        $lettersImported = $this->importLetters("{$basePath}/letters", $unmatched);
        // Accept either spelling — the spec called it "others", but a real
        // voice folder handed over used the singular "other".
        $othersDir = is_dir("{$basePath}/others") ? "{$basePath}/others" : "{$basePath}/other";
        $othersImported = $this->importKeywordMatched($othersDir, self::OTHERS_KEYWORDS, $unmatched);
        $seg1Imported = $this->importKeywordMatched("{$basePath}/seg_1", self::SEG_1_KEYWORDS, $unmatched);
        $seg2Imported = $this->importKeywordMatched("{$basePath}/seg_2", self::SEG_2_KEYWORDS, $unmatched);

        $this->newLine();
        $this->info("Imported {$numbersImported} number clip(s), {$lettersImported} letter clip(s), {$othersImported} other clip(s), {$seg1Imported} seg_1 clip(s), {$seg2Imported} seg_2 clip(s).");

        $missingNumbers = array_values(array_diff(range(1, 1000), $foundNumbers));
        if (! empty($missingNumbers)) {
            $this->warn(count($missingNumbers).' expected number(s) 1-1000 had no file at all (not a naming mismatch — just absent): '.implode(', ', $missingNumbers));
        }

        if (empty($unmatched)) {
            $this->info('No unmatched files.');
        } else {
            $this->warn(count($unmatched).' file(s) did not match an expected naming pattern and were skipped:');
            foreach ($unmatched as $file => $reason) {
                $this->line(" - {$file} ({$reason})");
            }
        }

        return self::SUCCESS;
    }

    private function importNumbers(string $dir, array &$unmatched, array &$foundNumbers): int
    {
        $count = 0;

        foreach ($this->mp3sIn($dir) as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if (! ctype_digit($filename)) {
                $unmatched[$file] = 'expected a whole number filename, e.g. "24.mp3"';
                continue;
            }

            $number = (int) $filename;

            if ($number < 1 || $number > 1000) {
                $unmatched[$file] = 'number out of the expected 1-1000 range';
                continue;
            }

            $foundNumbers[] = $number;
            $this->importClip($file, "number_{$number}");
            $count++;
        }

        return $count;
    }

    private function importLetters(string $dir, array &$unmatched): int
    {
        $count = 0;

        foreach ($this->mp3sIn($dir) as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if (! preg_match('/^[A-Za-z]$/', $filename)) {
                $unmatched[$file] = 'expected a single-letter filename, e.g. "A.mp3"';
                continue;
            }

            $this->importClip($file, 'letter_'.strtolower($filename));
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, list<string>>  $keywordMap  label => keywords to match against the lowercased filename
     */
    private function importKeywordMatched(string $dir, array $keywordMap, array &$unmatched): int
    {
        $count = 0;

        foreach ($this->mp3sIn($dir) as $file) {
            $filename = strtolower(pathinfo($file, PATHINFO_FILENAME));
            $matchedLabel = null;

            foreach ($keywordMap as $label => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($filename, $keyword)) {
                        $matchedLabel = $label;
                        break 2;
                    }
                }
            }

            if ($matchedLabel === null) {
                $unmatched[$file] = 'filename did not contain a recognized keyword for this folder';
                continue;
            }

            $this->importClip($file, $matchedLabel);
            $count++;
        }

        return $count;
    }

    /** @return list<string> */
    private function mp3sIn(string $dir): array
    {
        if (! is_dir($dir)) {
            $this->warn("Folder not found, skipping: {$dir}");

            return [];
        }

        return glob("{$dir}/*.mp3") ?: [];
    }

    private function importClip(string $sourcePath, string $label): void
    {
        $storedPath = "audio-clips/{$label}.mp3";
        Storage::disk('public')->put($storedPath, file_get_contents($sourcePath));

        AudioClip::updateOrCreate(
            ['label' => $label],
            ['file_path' => $storedPath, 'language' => 'sw']
        );
    }
}
