<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M04 «Закупки», заявки на материал (ERP-045). Legacy: material_requests(id, created_at, updated_at, material_name, qty, unit,
// order_num, item_name, workshop, requested_by, purchase_note, status). The status only follows the purchase: «Пришло на
// склад» does not move stock (a stock receipt is a document, ERP-046 after D-04).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('material_name', 255);
            $table->decimal('qty', 14, 4)->default(0);
            $table->string('unit', 32)->nullable();
            $table->string('order_num', 64)->nullable();
            $table->string('item_name', 500)->nullable();
            $table->string('workshop', 32)->nullable();
            $table->string('requested_by', 255)->nullable();
            $table->text('purchase_note')->nullable();
            $table->string('status', 16)->default('requested');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE material_requests ALTER COLUMN material_name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE material_requests ADD CONSTRAINT material_requests_name_not_blank CHECK (btrim(material_name) <> '')");
        DB::statement("ALTER TABLE material_requests ADD CONSTRAINT material_requests_status_known CHECK (status IN ('requested', 'in_purchase', 'received'))");
        DB::statement('ALTER TABLE material_requests ADD CONSTRAINT material_requests_qty_not_negative CHECK (qty >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
