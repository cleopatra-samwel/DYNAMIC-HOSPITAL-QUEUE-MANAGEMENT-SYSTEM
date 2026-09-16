<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * requested_tests (free text) is replaced by the structured
 * service_requested_tests checklist — see that table's migration.
 * requested_tests_other keeps the paper form's "OTHERS:" free-text line
 * for anything not on the catalog checklist; it stays on clinical_records
 * (per-visit) rather than per-service, matching the spec exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->text('requested_tests_other')->nullable()->after('doctor_preliminary_diagnosis');
        });

        // Carry forward whatever was already typed in the old free-text
        // field — it isn't checklist data (can't be reverse-mapped onto
        // catalog rows), but it's exactly what requested_tests_other is
        // for: free text the structured list doesn't cover.
        DB::table('clinical_records')->whereNotNull('requested_tests')->where('requested_tests', '!=', '')
            ->update(['requested_tests_other' => DB::raw('requested_tests')]);

        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn('requested_tests');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn('requested_tests_other');
            $table->text('requested_tests')->nullable()->after('doctor_preliminary_diagnosis');
        });
    }
};
