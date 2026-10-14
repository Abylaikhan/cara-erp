<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M03 «Склад» (ERP-038): physical warehouses, materials and supplier-name aliases.
// Legacy: PHYSICAL_WAREHOUSES in code (L27578); table warehouse(id uuid, created_at, name, category, unit, qty, price, min_qty,
// supplier, warehouse_id); table material_aliases(id uuid, created_at, material_name, alias_name, category).
// Stock is only read here: receipts, transfers, write-offs and inventory move it later as documents (ERP-039/040, D-04/D-05),
// so there is no rule on negative stock yet.
return new class extends Migration
{
    private const WAREHOUSES = [
        ['wh_plates', 'Склад плитных материалов', '🪵', '#d97706', 'material', ['ЛДСП/МДФ', 'Шпон', 'Стекло']],
        ['wh_fabric', 'Склад тканей', '🧵', '#7c3aed', 'material', ['Ткань/Обивочные', 'Поролон/Набивка', 'Мягкая фурнитура']],
        ['wh_wood', 'Склад леса', '🌲', '#16a34a', 'material', ['Пиломатериалы']],
        ['wh_paint', 'Склад краски', '🎨', '#dc2626', 'material', ['ЛКМ/Химия', 'Расходники']],
        ['wh_main', 'Основной склад', '🏭', '#4f8ef7', 'material', ['Фурнитура/Крепёж', 'Крепёж Novus', 'Инструмент/Абразивы', 'Металл/Профиль', 'Прочее']],
        ['wh_semi', 'Склад полуфабрикатов', '🔨', '#f59e0b', 'semi', ['Полуфабрикаты', 'Заготовки', 'НЗП']],
        ['wh_finished', 'Склад готовой продукции', '🪑', '#0ea5e9', 'product', ['Столы', 'Стулья', 'Диваны', 'Кресла', 'Кровати', 'Комоды', 'Шкафы', 'Тумбы',
            'Витрины', 'Зеркала', 'Консоли', 'Пуфики', 'Мягкая мебель', 'Кабинет', 'Коллекция ГРЕЙС', 'Коллекция ANY', 'Коллекция SKY', 'Коллекция НЕО БЕНТ',
            'Коллекция NEO', 'Коллекция Вельвет', 'Коллекция AIR', 'Реставрация', 'Шкаф AIR', 'Шкаф NEO', 'Шкаф ANY', 'Шкаф SKY', 'Шкаф АЖУР', 'Комоды', 'Кухни',
            'Эксклюзив', 'Гостиные DECO', 'Гостиные эконом', 'Шкафы и ящики']],
        ['wh_salon_armada', 'Склад салона Армада', '🏬', '#8b5cf6', 'product', ['Салон Армада']],
        ['wh_salon_naz', 'Склад салона Назарбаева', '🏪', '#ec4899', 'product', ['Салон Назарбаева']],
    ];

    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('name', 100);
            $table->string('icon', 16);
            $table->string('color', 16);
            $table->string('type', 16);
            // Categories legacy groups under this warehouse on «По складам» (by category, not by the stock's warehouse).
            $table->jsonb('categories');
            $table->unsignedSmallInteger('sort_order');
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_type_known CHECK (type IN ('material', 'semi', 'product'))");
        $now = now();
        foreach (self::WAREHOUSES as $i => [$code, $name, $icon, $color, $type, $categories]) {
            DB::table('warehouses')->insert(['code' => $code, 'name' => $name, 'icon' => $icon, 'color' => $color, 'type' => $type,
                'categories' => json_encode($categories, JSON_UNESCAPED_UNICODE), 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('category', 100);
            $table->string('unit', 16)->default('шт');
            $table->string('sku', 64)->nullable();
            $table->decimal('qty', 18, 3)->default(0);
            $table->decimal('price', 18, 2)->default(0);
            $table->decimal('min_qty', 18, 3)->default(0);
            $table->string('supplier_name', 255)->nullable();
            $table->string('warehouse_code', 64)->nullable();
            $table->foreign('warehouse_code')->references('code')->on('warehouses')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'category']);
            $table->index(['active', 'warehouse_code']);
        });
        // Sorted like the legacy database (docs/adr/0005-catalog.md, decision 5).
        DB::statement('ALTER TABLE materials ALTER COLUMN name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE materials ADD CONSTRAINT materials_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE materials ADD CONSTRAINT materials_price_min_not_negative CHECK (price >= 0 AND min_qty >= 0)');

        Schema::create('material_aliases', function (Blueprint $table) {
            $table->id();
            // Legacy links an alias to a material by its name, not by id (loadAliasesFromDB, L27389).
            $table->string('material_name', 255);
            $table->string('alias_name', 255);
            $table->string('category', 100)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->decimal('coeff', 12, 3)->default(1);
            $table->string('comment', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestampsTz(6);
            $table->index('material_name');
        });
        DB::statement('ALTER TABLE material_aliases ALTER COLUMN material_name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement('ALTER TABLE material_aliases ALTER COLUMN alias_name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE material_aliases ADD CONSTRAINT material_aliases_names_not_blank CHECK (btrim(material_name) <> '' AND btrim(alias_name) <> '')");
        DB::statement('ALTER TABLE material_aliases ADD CONSTRAINT material_aliases_coeff_positive CHECK (coeff > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_aliases');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('warehouses');
    }
};
