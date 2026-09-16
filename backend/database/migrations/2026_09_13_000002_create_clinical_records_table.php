<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained()->cascadeOnDelete();

            // Registration Staff / Administrator.
            $table->text('chief_complaint')->nullable();

            // Doctor only (initial consultation).
            $table->text('doctor_symptoms_notes')->nullable();
            $table->text('doctor_preliminary_diagnosis')->nullable();
            $table->text('requested_tests')->nullable();
            $table->string('referral_target')->nullable(); // laboratory | pharmacy | none

            // Laboratory Staff only.
            $table->text('lab_results_notes')->nullable();
            $table->foreignId('lab_technician_id')->nullable()->constrained('users')->nullOnDelete();

            // Doctor only (final review, after Lab if applicable).
            $table->text('final_diagnosis')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->string('patient_signature_name')->nullable();
            $table->string('patient_signature_phone')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_records');
    }
};
