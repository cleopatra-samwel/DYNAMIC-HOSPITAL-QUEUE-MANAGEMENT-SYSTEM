<?php

namespace Tests\Feature;

use App\Models\AudioClip;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AudioClipManagementTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_only_administrator_may_upload_an_audio_clip(): void
    {
        $file = UploadedFile::fake()->create('digit_5.wav', 10, 'audio/wav');

        $this->actingAs($this->doctor)->postJson('/api/audio-clips', [
            'label' => 'digit_5',
            'file' => $file,
        ])->assertStatus(403);

        $administrator = $this->makeUser('Administrator');
        $this->actingAs($administrator)->postJson('/api/audio-clips', [
            'label' => 'digit_5',
            'file' => $file,
        ])->assertStatus(201)->assertJsonPath('audio_clip.label', 'digit_5');

        $this->assertDatabaseHas('audio_clips', ['label' => 'digit_5', 'language' => 'en']);
    }

    public function test_re_uploading_the_same_label_replaces_it_instead_of_duplicating(): void
    {
        $administrator = $this->makeUser('Administrator');

        $first = UploadedFile::fake()->create('dept_lab_v1.wav', 5, 'audio/wav');
        $this->actingAs($administrator)->postJson('/api/audio-clips', ['label' => 'dept_lab', 'file' => $first])->assertStatus(201);
        $firstClipId = AudioClip::where('label', 'dept_lab')->value('id');

        $second = UploadedFile::fake()->create('dept_lab_v2.wav', 8, 'audio/wav');
        $this->actingAs($administrator)->postJson('/api/audio-clips', ['label' => 'dept_lab', 'file' => $second])->assertStatus(201);

        $this->assertSame(1, AudioClip::where('label', 'dept_lab')->count(), 'Re-uploading the same label must replace it, not create a duplicate row.');
        $this->assertSame($firstClipId, AudioClip::where('label', 'dept_lab')->value('id'), 'Same row, updated in place — not a new one.');

        // The path is deterministic (built from label+extension), so a
        // replace correctly overwrites the same file rather than leaving
        // an orphaned old one on disk under a different name.
        $path = AudioClip::where('label', 'dept_lab')->value('file_path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_only_administrator_may_list_audio_clips(): void
    {
        AudioClip::create(['label' => 'digit_1', 'file_path' => 'audio-clips/digit_1.wav', 'language' => 'en']);

        $this->actingAs($this->doctor)->getJson('/api/audio-clips')->assertStatus(403);

        $administrator = $this->makeUser('Administrator');
        $response = $this->actingAs($administrator)->getJson('/api/audio-clips')->assertStatus(200);
        $this->assertNotEmpty($response->json('audio_clips'));
        $this->assertArrayHasKey('url', $response->json('audio_clips.0'));
    }

    public function test_upload_rejects_a_disallowed_file_type(): void
    {
        $administrator = $this->makeUser('Administrator');
        $file = UploadedFile::fake()->create('not-audio.txt', 5, 'text/plain');

        $this->actingAs($administrator)->postJson('/api/audio-clips', [
            'label' => 'digit_9',
            'file' => $file,
        ])->assertStatus(422);
    }

    public function test_only_administrator_may_run_the_health_check(): void
    {
        $this->actingAs($this->doctor)->getJson('/api/audio-clips/health-check')->assertStatus(403);

        $administrator = $this->makeUser('Administrator');
        $this->actingAs($administrator)->getJson('/api/audio-clips/health-check')->assertStatus(200);
    }

    public function test_health_check_reports_clips_whose_file_is_missing_and_ignores_healthy_ones(): void
    {
        Storage::disk('public')->put('audio-clips/digit_2.wav', 'real bytes');
        AudioClip::create(['label' => 'digit_2', 'file_path' => 'audio-clips/digit_2.wav', 'language' => 'en']);

        // Row exists, nothing was ever put at this path — the dead one.
        AudioClip::create(['label' => 'digit_3', 'file_path' => 'audio-clips/digit_3.wav', 'language' => 'en']);

        $administrator = $this->makeUser('Administrator');
        $response = $this->actingAs($administrator)->getJson('/api/audio-clips/health-check')->assertStatus(200);

        $this->assertSame(2, $response->json('total_clips'));
        $this->assertSame(1, $response->json('missing_count'));
        $missingLabels = collect($response->json('missing'))->pluck('label');
        $this->assertTrue($missingLabels->contains('digit_3'));
        $this->assertFalse($missingLabels->contains('digit_2'), 'A clip whose file genuinely exists must not be reported as missing.');
    }

    public function test_health_check_reports_no_missing_clips_when_everything_is_healthy(): void
    {
        Storage::disk('public')->put('audio-clips/digit_4.wav', 'real bytes');
        AudioClip::create(['label' => 'digit_4', 'file_path' => 'audio-clips/digit_4.wav', 'language' => 'en']);

        $administrator = $this->makeUser('Administrator');
        $response = $this->actingAs($administrator)->getJson('/api/audio-clips/health-check')->assertStatus(200);

        $this->assertSame(0, $response->json('missing_count'));
        $this->assertEmpty($response->json('missing'));
    }
}
