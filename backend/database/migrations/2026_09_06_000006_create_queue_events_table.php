<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_events');
    }
};
