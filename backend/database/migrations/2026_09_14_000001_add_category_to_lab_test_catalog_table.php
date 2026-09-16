<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_test_catalog', function (Blueprint $table) {
            // Nullable — pre-existing rows seeded before this structured
            // checklist project aren't retroactively categorized, they
            // just fall under the checklist UI's "Other" bucket.
            $table->string('category')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('lab_test_catalog', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
