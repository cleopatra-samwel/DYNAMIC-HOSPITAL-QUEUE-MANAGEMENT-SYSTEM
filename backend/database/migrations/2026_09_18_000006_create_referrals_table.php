<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor Role expansion — Referrals. Tracks the reason/notes/who/when for
 * a "Refer to Another Department" Next Action, distinct from the
 * QueueTicket/Service pair the referral actually created (to_service_id).
 *
 * Deliberately has NO status column — status is computed live from
 * to_service_id's QueueTicket status (see Referral::getStatusAttribute()),
 * the same way Visit.overall_status is derived rather than hand-set. A
 * stored status here would need every ticket transition to remember to
 * keep it in sync — this avoids that drift risk entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('to_service_id')->nullable()->constrained('services')->nullOnDelete();
            // Kept as its own column (not derived solely via to_service_id)
            // so the original referral target stays known even if that
            // service is later forwarded on again.
            $table->foreignId('to_department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
