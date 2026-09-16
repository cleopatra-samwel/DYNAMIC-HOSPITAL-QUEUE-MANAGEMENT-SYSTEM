<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_events', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('queue_events', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
