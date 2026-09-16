<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('visit_date');
            $table->string('patient_type'); // Normal | Emergency
            $table->boolean('emergency_confirmed')->default(false);
            $table->string('payment_method'); // Cash | Insurance
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_ref')->nullable();
            $table->string('overall_status')->default('Registered');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
