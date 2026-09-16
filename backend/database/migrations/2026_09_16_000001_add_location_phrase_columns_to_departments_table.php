<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voice Announcements v2 — which location word ("Chumba namba" / "Dirisha
 * namba") and whether "Daktari" follows it, per department. counter_number
 * itself (added in an earlier migration) is unchanged and still supplies
 * the spoken location number regardless of wording.
 */
return new class extends Migration
{
    private const LOCATION_PHRASE_TYPE_BY_DEPT_CODE = [
        'REG' => 'window',
        'CONS' => 'room',
        'LAB' => 'room',
        'PHARM' => 'window',
        'BILL' => 'window',
    ];

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->enum('location_phrase_type', ['room', 'window'])->nullable()->after('counter_number');
            $table->boolean('append_doctor_phrase')->default(false)->after('location_phrase_type');
        });

        // Backfill existing rows (same rationale as the counter_number
        // migration: DepartmentSeeder alone wouldn't reach rows already in
        // the database from an earlier phase).
        foreach (self::LOCATION_PHRASE_TYPE_BY_DEPT_CODE as $deptCode => $locationPhraseType) {
            DB::table('departments')->where('dept_code', $deptCode)->update(['location_phrase_type' => $locationPhraseType]);
        }

        DB::table('departments')->where('dept_code', 'CONS')->update(['append_doctor_phrase' => true]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['location_phrase_type', 'append_doctor_phrase']);
        });
    }
};
