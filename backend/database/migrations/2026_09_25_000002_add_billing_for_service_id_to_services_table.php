<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Billing-queue service exists only to hold the cashier's ticket for
        // ANOTHER service that requires payment (Consultation/Laboratory/
        // Pharmacy). It is NOT part of the clinical journey, so it links to
        // the service being paid for here instead of via previous_service_id
        // (which would make it that service's "next service").
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('billing_for_service_id')->nullable()->after('previous_service_id')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_for_service_id');
        });
    }
};
