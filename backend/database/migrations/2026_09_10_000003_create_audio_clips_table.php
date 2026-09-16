<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_clips', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->string('file_path');
            $table->string('language')->default('en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_clips');
    }
};
