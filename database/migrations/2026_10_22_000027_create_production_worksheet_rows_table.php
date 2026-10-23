<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Рабочий лист производства» (legacy production_worksheet): the order lines put on the worksheet, by the order number and the
// line's place in the order («номер|индекс», as the progress of the line). The line is linked where the order and the line
// exist; legacy lists the rows in the natural order of its table (legacy_position, `legacy:natural-order`). Adding, removing
// and clearing wait for the worksheet with posting (ERP-041/042).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_worksheet_rows', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('legacy_id')->unique();
            $table->string('order_num', 32);
            $table->smallInteger('item_idx');
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->timestampTz('added_at', 6)->nullable();
            $table->integer('legacy_position')->nullable();
            $table->timestampsTz(6);
            $table->unique(['order_num', 'item_idx']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_worksheet_rows');
    }
};
