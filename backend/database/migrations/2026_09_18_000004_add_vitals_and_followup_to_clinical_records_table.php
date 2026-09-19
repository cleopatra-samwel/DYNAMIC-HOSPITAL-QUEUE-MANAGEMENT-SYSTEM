<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor Role expansion — Vital Signs and Follow-up, both Doctor-owned
 * (see ClinicalRecordController::SECTION_OWNERS), same access pattern as
 * doctor_symptoms_notes/final_diagnosis etc. All nullable — a doctor may
 * record some, all, or none of these per consultation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->decimal('temperature', 4, 1)->nullable()->after('doctor_preliminary_diagnosis');
            $table->string('blood_pressure', 20)->nullable()->after('temperature');
            $table->decimal('weight', 5, 2)->nullable()->after('blood_pressure');
            $table->unsignedSmallInteger('pulse_rate')->nullable()->after('weight');
            $table->date('follow_up_date')->nullable()->after('treatment_plan');
            $table->text('follow_up_instructions')->nullable()->after('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn(['temperature', 'blood_pressure', 'weight', 'pulse_rate', 'follow_up_date', 'follow_up_instructions']);
        });
    }
};
