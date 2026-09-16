<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudioClip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8 — lets an Administrator upload/replace announcement audio
 * without any code change. Nothing else in the codebase ever hardcodes a
 * file path — every consumer (AnnouncementController) looks clips up by
 * label, so swapping a placeholder for a real recording here is the
 * entire "replace it" workflow.
 */
class AudioClipController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may manage audio clips.');

        $clips = AudioClip::orderBy('label')->get()->map(fn (AudioClip $clip) => [
            'id' => $clip->id,
            'label' => $clip->label,
            'language' => $clip->language,
            'url' => Storage::disk('public')->url($clip->file_path),
            'updated_at' => $clip->updated_at,
        ]);

        return response()->json(['audio_clips' => $clips]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may manage audio clips.');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['sometimes', 'string', 'max:10'],
            'file' => ['required', 'file', 'mimes:mp3,wav', 'max:5120'],
        ]);

        $language = $data['language'] ?? 'en';
        $extension = $request->file('file')->extension();
        $path = $request->file('file')->storeAs('audio-clips', "{$data['label']}.{$extension}", 'public');

        // updateOrCreate by label — an Administrator re-uploading the same
        // label replaces the clip in place, it doesn't create a duplicate.
        $clip = AudioClip::updateOrCreate(
            ['label' => $data['label']],
            ['file_path' => $path, 'language' => $language]
        );

        return response()->json([
            'audio_clip' => [
                'id' => $clip->id,
                'label' => $clip->label,
                'language' => $clip->language,
                'url' => Storage::disk('public')->url($clip->file_path),
            ],
        ], 201);
    }

    /**
     * Audits every audio_clips row against the actual filesystem — the
     * real fix for noticing a dead file_path (deleted by hand, a bad
     * upload) rather than relying on AnnouncementController's per-request
     * warning log, which only catches a gap the moment some patient's
     * queue_number happens to need that exact label. An Administrator can
     * hit this on demand (or it could be wired to a scheduled command
     * later) to see every gap at once, not one at a time.
     */
    public function healthCheck(Request $request)
    {
        abort_unless($request->user()->hasRole('Administrator'), 403, 'Only Administrators may manage audio clips.');

        $clips = AudioClip::orderBy('label')->get();

        $missing = $clips->filter(fn (AudioClip $clip) => ! Storage::disk('public')->exists($clip->file_path))
            ->map(fn (AudioClip $clip) => [
                'id' => $clip->id,
                'label' => $clip->label,
                'file_path' => $clip->file_path,
            ])
            ->values();

        return response()->json([
            'total_clips' => $clips->count(),
            'missing_count' => $missing->count(),
            'missing' => $missing,
        ]);
    }
}
