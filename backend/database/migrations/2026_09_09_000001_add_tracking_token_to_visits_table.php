<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 7 — the public patient tracking page needs an opaque identifier
 * that isn't the visit's numeric id (never expose that publicly) and isn't
 * guessable/enumerable. Phase 2's tracking endpoint was specced to already
 * use this column, but it was never actually added — this is the first
 * time it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->uuid('tracking_token')->nullable()->unique()->after('id');
        });

        // Backfill any pre-existing rows — new rows get one from
        // Visit::booted()'s creating hook, so this only matters here.
        DB::table('visits')->whereNull('tracking_token')->orderBy('id')->each(function ($visit) {
            DB::table('visits')->where('id', $visit->id)->update(['tracking_token' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
