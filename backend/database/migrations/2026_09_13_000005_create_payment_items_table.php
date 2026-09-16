<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('catalog_type'); // lab_test | medication | other
            // Not a real FK constraint — catalog_item_id points into
            // lab_test_catalog OR medication_catalog depending on
            // catalog_type (a single column can't carry two FK targets),
            // and is always null when catalog_type is "other".
            $table->unsignedBigInteger('catalog_item_id')->nullable();
            $table->string('custom_label')->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
    }
};
