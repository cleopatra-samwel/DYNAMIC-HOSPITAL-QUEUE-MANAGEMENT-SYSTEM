<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prescribed_medications', function (Blueprint $table) {
            $table->id();
            // The PRESCRIBING Consultation service, not whichever service is
            // currently viewing it — see Service::pharmacyRequestOriginService().
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Catalog rows are only ever deactivated, never deleted (see
            // MedicationCatalogController) — restrict rather than cascade so a
            // historical prescription record can never silently disappear.
            $table->foreignId('medication_catalog_id')->constrained('medication_catalog')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'medication_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prescribed_medications');
    }
};
