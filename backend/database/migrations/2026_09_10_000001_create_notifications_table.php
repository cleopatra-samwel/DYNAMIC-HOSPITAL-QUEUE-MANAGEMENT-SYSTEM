<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — despite the spec's assumption, no notifications table existed
 * before this (QueueEventController's "notification list" was actually a
 * synthetic feed built from queue_events, not a real table — see its own
 * doc comment). Patient-facing rows (WAITING/APPROACHING/CALLED/
 * TRANSFERRED/COMPLETED) are scoped by visit_id so the public tracking
 * endpoint can filter to exactly one visit's own notifications; staff
 * facing rows (LONG_WAIT_ALERT) use department_id/queue_ticket_id instead
 * and leave visit_id null — there's no single "recipient user" concept for
 * those, any Administrator can see all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('message');
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('queue_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
