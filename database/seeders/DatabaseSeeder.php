<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Idempotent synthetic development seed. No real employees, clients, legacy logins' passwords or legacy names.
 * Users: UsersSeeder (one per legacy role template, password = DEV_ADMIN_PASSWORD from .env).
 * Re-running keeps existing records (and their passwords) unchanged.
 */
class DatabaseSeeder extends Seeder
{
    /** Synthetic expense directory (legacy keeps it only in the database — nothing to copy from the HTML). */
    private const CATEGORIES = [
        ['code' => 'general', 'name' => 'Общие расходы', 'sort_order' => 10, 'children' => [
            ['code' => 'gen_electricity', 'name' => 'Электроэнергия', 'is_fixed' => true, 'sort_order' => 10],
            ['code' => 'gen_rent', 'name' => 'Аренда помещений', 'is_fixed' => true, 'sort_order' => 20],
            ['code' => 'gen_internet', 'name' => 'Связь и интернет', 'is_fixed' => true, 'sort_order' => 30],
        ]],
        ['code' => 'production', 'name' => 'Производство', 'sort_order' => 20, 'children' => [
            ['code' => 'prod_materials', 'name' => 'Материалы', 'sort_order' => 10],
            ['code' => 'prod_tools', 'name' => 'Инструмент и оснастка', 'sort_order' => 20],
        ]],
        ['code' => 'sales_marketing', 'name' => 'Продажи и маркетинг', 'sort_order' => 30, 'children' => [
            ['code' => 'mkt_ads', 'name' => 'Реклама', 'sort_order' => 10],
            ['code' => 'mkt_delivery', 'name' => 'Доставка клиентам', 'sort_order' => 20],
        ]],
    ];

    /** A few synthetic products for a fresh environment; skipped once real products were imported or exist. */
    private function seedProducts(): void
    {
        if (Product::query()->exists() || DB::table('legacy_id_map')->where('target_table', 'products')->exists()) {
            return;
        }
        $items = [
            ['Стул «Демо 1»', 'Стул', 45000, 38000], ['Стул «Демо 2»', 'Стул', 52000, 44000],
            ['Стол «Демо»', 'Стол', 180000, 150000], ['Кровать «Демо»', 'Кровать', 320000, 270000],
            ['Диван «Демо»', 'Мягкая мебель', 410000, 0], ['Шкаф «Демо»', 'Корпусная мебель', 0, 0],
        ];
        foreach ($items as [$name, $category, $retail, $wholesale]) {
            Product::query()->create(['name' => $name, 'category' => $category, 'price_retail' => $retail, 'price_wholesale' => $wholesale, 'unit' => 'шт']);
        }
    }

    /** Synthetic customers of several channels; skipped once real customers were imported or exist. */
    private function seedCustomers(): void
    {
        if (Customer::query()->exists() || DB::table('legacy_id_map')->where('target_table', 'customers')->exists()) {
            return;
        }
        $items = [
            ['ТОО «Демо Мебель»', Customer::LEGAL, 'office', '000000000001', '+7 700 000 0001'],
            ['ИП Демо', Customer::LEGAL, 'office', null, '+7 700 000 0002'],
            ['Демо Покупатель', Customer::INDIVIDUAL, 'office', null, null],
            ['Демо Клиент Армада', 'client', 'salon_armada', null, '+7 700 000 0003'],
            ['Демо Клиент Назарбаева', 'client', 'salon_nazarbayeva', null, '+7 700 000 0004'],
        ];
        foreach ($items as [$name, $type, $channel, $inn, $phone]) {
            (new Customer(['name' => $name, 'type' => $type, 'inn' => $inn, 'phone' => $phone]))->forceFill(['channel' => $channel])->save();
        }
    }

    /** Synthetic suppliers; skipped once real suppliers were imported or exist. Runs after the expense directory. */
    private function seedSuppliers(): void
    {
        if (Supplier::query()->exists() || DB::table('legacy_id_map')->where('target_table', 'suppliers')->exists()) {
            return;
        }
        $rent = ExpenseCategory::query()->where('code', 'gen_rent')->value('id');
        $items = [
            ['name' => 'ТОО «Демо Плита»', 'category' => 'ЛДСП', 'bin' => '000000000011', 'lead_time_days' => 5, 'turnover' => 2500000],
            ['name' => 'ТОО «Демо Ткани»', 'category' => 'Ткань', 'status' => 'pause', 'defect_pct' => 1.5],
            ['name' => 'ИП Демо Фурнитура', 'category' => 'Фурнитура', 'contact' => 'Демо Контакт', 'phone' => '+7 700 000 0011'],
            ['name' => 'ТОО «Демо Аренда»', 'expense_category_id' => $rent],
        ];
        foreach ($items as $item) {
            Supplier::query()->create($item);
        }
    }

    /** Synthetic warehouse directory; skipped once real materials were imported or exist. */
    private function seedMaterials(): void
    {
        if (Material::query()->exists() || DB::table('legacy_id_map')->where('target_table', 'materials')->exists()) {
            return;
        }
        $items = [
            ['ЛДСП Демо 16мм белый', 'ЛДСП/МДФ', 'лист', '24', '8500', '10', 'wh_plates'],
            ['ЛДСП Демо 16мм дуб', 'ЛДСП/МДФ', 'лист', '4', '9200', '10', 'wh_plates'],
            ['Ткань Демо рогожка', 'Ткань/Обивочные', 'м', '120.5', '3200', '0', 'wh_fabric'],
            ['Поролон Демо ППУ-40', 'Поролон/Набивка', 'лист', '0', '18500', '0', 'wh_fabric'],
            ['Петля Демо мебельная', 'Фурнитура/Крепёж', 'шт', '300', '280', '100', 'wh_main'],
            ['Лак Демо матовый', 'ЛКМ/Химия', 'кан', '7', '12000', '5', 'wh_paint'],
        ];
        foreach ($items as [$name, $category, $unit, $qty, $price, $min, $warehouse]) {
            (new Material(['name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price, 'min_qty' => $min, 'warehouse_code' => $warehouse]))
                ->forceFill(['qty' => $qty])->save();
        }
        MaterialAlias::query()->create(['material_name' => 'ЛДСП Демо 16мм белый', 'alias_name' => 'Плита ЛДСП Демо 16/2800/2070 белая', 'category' => 'ЛДСП/МДФ']);
    }

    /** Two synthetic incoming ESF of a synthetic supplier; skipped once real ESF were imported or exist. */
    private function seedIncomingInvoices(): void
    {
        if (IncomingInvoice::query()->exists() || DB::table('legacy_id_map')->where('target_table', 'incoming_invoices')->exists()) {
            return;
        }
        $supplier = Supplier::query()->where('name', 'ТОО «Демо Плита»')->first();
        $sum = fn (array $lines, int $column) => array_reduce($lines, fn (string $s, array $line) => bcadd($s, $line[$column], 2), '0');
        $items = [
            ['ESF-000000000011-DEMO-0001', '1', '2026-01-15', 'goods', [['ЛДСП Демо 16мм белый', '10', '8500.00', '85000.00', '12.00', '10200.00', '95200.00']]],
            ['ESF-000000000011-DEMO-0002', '2', '2026-02-10', 'service', [['Доставка демо', '1', '5000.00', '5000.00', '12.00', '600.00', '5600.00']]],
        ];
        foreach ($items as [$reg, $num, $date, $type, $lines]) {
            $invoice = IncomingInvoice::query()->create([
                'reg_num' => $reg, 'num' => $num, 'date' => $date, 'status' => 'DELIVERED', 'invoice_type' => $type,
                'supplier_id' => $supplier?->id, 'supplier_name' => 'ТОО «Демо Плита»', 'supplier_bin' => '000000000011',
                'total_amount' => $sum($lines, 3), 'total_nds' => $sum($lines, 5), 'total_with_nds' => $sum($lines, 6),
            ]);
            foreach ($lines as $n => [$description, $qty, $price, $amount, $rate, $nds, $total]) {
                IncomingInvoiceItem::query()->create(['invoice_id' => $invoice->id, 'line_no' => $n + 1, 'description' => $description, 'qty' => $qty,
                    'unit_price' => $price, 'amount' => $amount, 'nds_rate' => $rate, 'nds_amount' => $nds, 'total' => $total]);
            }
        }
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The synthetic development seed must not run in production.');
        }
        $this->callSilent(UsersSeeder::class);

        $this->seedProducts();
        $this->seedCustomers();
        $this->seedMaterials();

        // Real directory imported from the legacy snapshot (legacy:import) replaces the synthetic one.
        if (DB::table('legacy_id_map')->where('target_table', 'expense_categories')->exists()) {
            $this->seedSuppliers();
            $this->seedIncomingInvoices();

            return;
        }
        foreach (self::CATEGORIES as $top) {
            $parent = ExpenseCategory::query()->firstOrCreate(['code' => $top['code']],
                ['name' => $top['name'], 'sort_order' => $top['sort_order']]);
            foreach ($top['children'] as $child) {
                ExpenseCategory::query()->firstOrCreate(['code' => $child['code']], [
                    'name' => $child['name'], 'parent_id' => $parent->id,
                    'is_fixed' => $child['is_fixed'] ?? false, 'sort_order' => $child['sort_order'],
                ]);
            }
        }
        $this->seedSuppliers();
        $this->seedIncomingInvoices();
    }
}
