<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor Role expansion — per-test structured lab results. Previously the
 * only place a result could be recorded was clinical_records.lab_results_notes,
 * one free-text field for the whole visit. This adds one result per
 * requested test, entered by Laboratory Staff (see
 * RequestedTestController::updateResults). lab_results_notes is kept as an
 * optional overall summary alongside these, not replaced.
 *
 * status is validated via Rule::in() at the controller level, not a DB
 * enum — matches this app's existing convention (patient_type,
 * payment_method).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requested_tests', function (Blueprint $table) {
            $table->string('result_value')->nullable()->after('lab_test_catalog_id');
            $table->string('reference_range')->nullable()->after('result_value');
            $table->string('status', 20)->nullable()->after('reference_range');
        });
    }

    public function down(): void
    {
        Schema::table('service_requested_tests', function (Blueprint $table) {
            $table->dropColumn(['result_value', 'reference_range', 'status']);
        });
    }
};
