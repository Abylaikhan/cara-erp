<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M02 «Производство», часть 1 (ERP-041, view only). Legacy production_progress(id, item_key «num|index», order_num, item_idx,
// item_name, stages, materials, completed, updated_at): what the production worksheet recorded for an order line — stages with
// their workshop, assignees and piece rates, materials taken. Kept as legacy stores them; posting stages and materials
// (wages, stock) comes with ERP-041/042 after decisions D-05 and D-06.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->jsonb('stages')->default('[]');
            $table->jsonb('materials')->default('[]');
            $table->boolean('completed')->default(false);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_progress');
    }
};
