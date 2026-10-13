<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M07 «Каталог». Legacy: products(id uuid, name, category, price_retail, price_wholesale, unit, active, created_by,
// updated_by, delete_requested, delete_requested_by). The legacy form also sends sku/description, which the legacy table
// does not have (docs/adr/0005-catalog.md) — here they are stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('category', 100)->index();
            $table->decimal('price_retail', 18, 2)->default(0);
            $table->decimal('price_wholesale', 18, 2)->default(0);
            $table->string('unit', 16)->default('шт');
            $table->string('sku', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at')->nullable();
            $table->boolean('delete_requested')->default(false);
            $table->foreignId('delete_requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delete_requested_by_name')->nullable();
            // Author as a link and as the name shown in legacy («Добавил»); legacy rows keep their text value.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['active', 'category', 'name']);
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_prices_not_negative CHECK (price_retail >= 0 AND price_wholesale >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_unit_known CHECK (unit IN ('шт', 'м.п.', 'м²', 'компл'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
