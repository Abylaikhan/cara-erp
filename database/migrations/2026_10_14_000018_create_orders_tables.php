<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M09 «Продажи», часть 1 (ERP-029/030, view only). Legacy: orders(id, num, created_at, created_date, date, delivery_date,
// payment_due, channel, customer, end_client, seller, payment, status, comment), order_items(id, order_num, name, item_type,
// category, qty, price, progress, status, resp, date, attributes, created_at) and realizations(id, order_num, doc_num,
// doc_date, customer, name, qty, unit, revenue, created_at). Dates legacy keeps as text in three forms are stored as dates.
// An item's place in its order is the legacy natural order (line_no): production progress refers to it by index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('num', 32)->unique();
            $table->string('channel', 64);
            $table->foreign('channel')->references('code')->on('sales_channels')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('customer', 255)->nullable();
            $table->string('end_client', 255)->nullable();
            $table->string('seller', 255)->nullable();
            $table->date('order_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('payment_due')->nullable();
            $table->unsignedSmallInteger('payment_pct')->default(0);
            $table->string('status', 16)->default('queue');
            $table->text('comment')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['channel', 'status']);
            $table->index('created_at');
        });
        DB::statement('ALTER TABLE orders ALTER COLUMN num TYPE varchar(32) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_num_not_blank CHECK (btrim(num) <> '')");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_payment_pct_range CHECK (payment_pct BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('queue', 'progress', 'ready', 'delivered', 'done', 'cancelled'))");

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('name', 500);
            $table->string('item_type', 64)->nullable();
            $table->string('category', 255)->nullable();
            $table->decimal('qty', 14, 4)->default(1);
            $table->decimal('price', 18, 2)->default(0);
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('status', 16)->default('queue');
            $table->string('resp', 255)->nullable();
            $table->date('item_date')->nullable();
            $table->jsonb('attrs')->default('{}'); // legacy «attributes»: the name is taken by Eloquent
            $table->timestampsTz(6);
            $table->index('order_id');
        });
        // Checked at commit: a re-import may move lines inside an order within one transaction.
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_order_line_unique UNIQUE (order_id, line_no) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_known CHECK (status IN ('queue', 'progress', 'ready', 'delivered', 'done', 'cancelled'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_progress_range CHECK (progress BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_not_negative CHECK (qty >= 0 AND price >= 0)');

        Schema::create('realizations', function (Blueprint $table) {
            $table->id();
            $table->string('order_num', 32)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('doc_num', 64)->nullable();
            $table->date('doc_date');
            $table->string('customer', 255)->nullable();
            $table->string('name', 500);
            $table->decimal('qty', 14, 4)->default(0);
            $table->string('unit', 32)->nullable();
            $table->decimal('revenue', 18, 2)->default(0);
            $table->timestampsTz(6);
            $table->index('order_id');
            $table->index('doc_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realizations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
