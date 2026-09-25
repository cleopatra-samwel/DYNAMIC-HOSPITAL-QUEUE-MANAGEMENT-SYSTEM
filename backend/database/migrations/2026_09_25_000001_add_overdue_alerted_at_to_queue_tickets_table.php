<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks that the "waiting more than the expected time without being
 * called" staff alert has already been raised for this ticket, so the
 * scheduled check fires it once per ticket instead of every minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->timestamp('overdue_alerted_at')->nullable()->after('long_wait_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropColumn('overdue_alerted_at');
        });
    }
};
