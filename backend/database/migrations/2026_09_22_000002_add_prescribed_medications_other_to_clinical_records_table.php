<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prescribed_medications_other keeps a free-text line for any medicine not
 * on the medication catalog checklist — same pattern as
 * requested_tests_other on this same table. Per-visit rather than
 * per-service, matching that column's precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->text('prescribed_medications_other')->nullable()->after('treatment_plan');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn('prescribed_medications_other');
        });
    }
};
