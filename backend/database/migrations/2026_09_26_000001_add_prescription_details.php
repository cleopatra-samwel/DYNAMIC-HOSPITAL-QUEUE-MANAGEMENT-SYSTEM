<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Doctor's prescription now records HOW each medicine is taken and how
 * many units to dispense (Billing charges quantity x price), plus optional
 * free-text prescription notes on the visit's clinical record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_prescribed_medications', function (Blueprint $table) {
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->unsignedInteger('quantity')->default(1);
        });

        Schema::table('clinical_records', function (Blueprint $table) {
            $table->text('prescription_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_prescribed_medications', function (Blueprint $table) {
            $table->dropColumn(['dosage', 'frequency', 'duration', 'quantity']);
        });

        Schema::table('clinical_records', function (Blueprint $table) {
            $table->dropColumn('prescription_notes');
        });
    }
};
