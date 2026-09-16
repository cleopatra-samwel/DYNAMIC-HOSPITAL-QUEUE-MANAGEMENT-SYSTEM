<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('priority_level_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('threshold_minutes');
            $table->timestamps();

            $table->unique(['department_id', 'priority_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_thresholds');
    }
};
