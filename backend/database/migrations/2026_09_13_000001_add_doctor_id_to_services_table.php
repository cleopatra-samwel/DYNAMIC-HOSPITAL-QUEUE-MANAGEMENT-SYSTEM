<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alongside department_id, not on queue_tickets — a Service is
        // already "which department/instance of care is this", and
        // doctor_id is the same kind of fact (only ever meaningful for a
        // Consultation-type service, always null elsewhere), not something
        // about the ticket's queue position/status.
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doctor_id');
        });
    }
};
