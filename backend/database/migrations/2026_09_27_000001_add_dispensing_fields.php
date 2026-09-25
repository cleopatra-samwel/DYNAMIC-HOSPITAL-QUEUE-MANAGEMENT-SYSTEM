<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy dispensing: which prescribed medicines were actually handed over
 * (and how many), and the patient's signature confirming they received
 * their medicines. The signature is a PNG data URL drawn on the dispensing
 * form; dispensing_signed_at is set by the server when it is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_prescribed_medications', function (Blueprint $table) {
            $table->unsignedInteger('dispensed_quantity')->nullable();
            $table->timestamp('dispensed_at')->nullable();
        });

        Schema::table('clinical_records', function (Blueprint $table) {
            $table->longText('dispensing_signature')->nullable();
            $table->timestamp('dispensing_signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_prescribed_medications', function (Blueprint $table) {
            $table->dropColumn(['dispensed_quantity', 'dispensed_at']);
        });

        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn(['dispensing_signature', 'dispensing_signed_at']);
        });
    }
};
