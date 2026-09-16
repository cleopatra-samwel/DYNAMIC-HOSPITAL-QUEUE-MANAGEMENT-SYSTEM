<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requested_tests', function (Blueprint $table) {
            $table->id();
            // The REQUESTING Consultation service, not whichever service is
            // currently viewing it — see Service::labRequestOriginService().
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Catalog rows are only ever deactivated, never deleted (see
            // LabTestCatalogController) — restrict rather than cascade so a
            // historical request record can never silently disappear.
            $table->foreignId('lab_test_catalog_id')->constrained('lab_test_catalog')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'lab_test_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requested_tests');
    }
};
