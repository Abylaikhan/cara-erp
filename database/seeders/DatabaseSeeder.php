<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Idempotent synthetic development seed. No real employees, clients, legacy logins' passwords or legacy names.
 * One user per legacy role template: login = role key, password = DEV_ADMIN_PASSWORD from .env.
 * Re-running keeps existing records (and their passwords) unchanged.
 */
class DatabaseSeeder extends Seeder
{
    /** Synthetic people: first letters give the avatar initials. */
    private const NAMES = [
        'director' => 'Демо Директор', 'manager' => 'Демо Продажи', 'storekeeper' => 'Демо Склад',
        'foreman' => 'Демо Цех', 'accountant' => 'Демо Бухгалтер', 'hr' => 'Демо Кадры', 'buyer' => 'Демо Закупщик',
        'salon_armada' => 'Демо Армада', 'salon_nazarbayeva' => 'Демо Назарбаева', 'salon_armada_mgr' => 'Демо Салоны',
        'dealer_astana' => 'Демо Астана', 'dealer_aktau' => 'Демо Актау', 'dealer_shymkent' => 'Демо Шымкент',
        'dealer_atyrau' => 'Демо Атырау', 'dealer_aktobe' => 'Демо Актобе',
    ];

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

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The synthetic development seed must not run in production.');
        }
        $password = env('DEV_ADMIN_PASSWORD');
        if (blank($password)) {
            throw new RuntimeException('DEV_ADMIN_PASSWORD is not set in .env.');
        }
        $hash = Hash::make($password);

        foreach (config('erp.roles') as $role => $template) {
            User::query()->firstOrCreate(['login' => $role], [
                'name' => self::NAMES[$role] ?? 'Демо Пользователь',
                'role' => $role,
                'channel' => $template['channel'] ?? null,
                'is_active' => true,
                'password' => $hash,
            ]);
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
    }
}
