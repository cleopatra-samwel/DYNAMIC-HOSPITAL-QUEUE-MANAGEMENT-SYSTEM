<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date_of_birth');
            $table->string('gender');
            $table->string('contact');
            // PENDING (awaiting staff call) / CALLED (no digital ticket at
            // this stage, so this is reserved for a future in-person-call
            // flow) / CONVERTED (turned into a real Patient+Visit).
            $table->string('status')->default('PENDING');
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
