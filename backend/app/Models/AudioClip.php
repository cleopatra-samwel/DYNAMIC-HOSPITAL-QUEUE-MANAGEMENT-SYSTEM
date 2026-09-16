<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * label is the stable key everything else references (e.g. "digit_7",
 * "letter_c", "dept_lab", "phrase_please_proceed_to") — file_path is just
 * whatever an Administrator most recently uploaded for that label via
 * AudioClipController::store, never hardcoded elsewhere in the codebase.
 */
#[Fillable(['label', 'file_path', 'language'])]
class AudioClip extends Model
{
    use HasFactory;
}
