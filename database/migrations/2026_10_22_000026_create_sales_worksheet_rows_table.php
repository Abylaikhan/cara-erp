<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «Рабочий лист реализации» (legacy sales_worksheet): the lines of the delivery notes — a note is the lines of one doc_id. The
// lines are kept as legacy keeps them: the order by its number (the order link is resolved where the order exists), the
// product as typed, the amounts exactly. Posting into «Реализация» and delivering wait for D-08; the note's realization is
// kept by its legacy uuid and linked where it was imported.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_worksheet_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('doc_id');
            $table->string('doc_num', 32);
            $table->date('doc_date');
            $table->smallInteger('line_no')->default(0);
            $table->string('line_kind', 32)->default('sale');
            $table->string('order_num', 32)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('customer', 255)->nullable();
            $table->string('item_name', 255);
            $table->smallInteger('item_no')->nullable();
            $table->text('attrs')->nullable();
            $table->boolean('attrs_short')->default(false);
            $table->string('unit', 16)->nullable();
            $table->decimal('qty', 30, 12);
            $table->decimal('price', 30, 12);
            $table->decimal('amount', 30, 12);
            $table->string('driver', 255)->nullable();
            $table->text('note')->nullable();
            $table->boolean('posted')->default(false);
            $table->timestampTz('posted_at', 6)->nullable();
            $table->uuid('realization_uuid')->nullable();
            $table->foreignId('realization_id')->nullable()->constrained('realizations')->nullOnDelete();
            $table->uuid('restoration_uuid')->nullable();
            $table->boolean('delivered')->default(false);
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->string('delivered_by', 64)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->string('created_by_name', 255)->nullable();
            $table->timestampsTz(6);
            $table->index(['doc_date', 'doc_num']);
            $table->index('doc_id');
        });
        // sorted and searched as in Supabase (legacy order=doc_date.desc,doc_num.desc,line_no.asc)
        DB::statement('ALTER TABLE sales_worksheet_rows ALTER COLUMN customer TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement('ALTER TABLE sales_worksheet_rows ALTER COLUMN doc_num TYPE varchar(32) COLLATE "en-US-x-icu"');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_worksheet_rows');
    }
};
