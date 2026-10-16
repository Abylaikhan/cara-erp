<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The synthetic users only: one per legacy role template, login = role key, password = DEV_ADMIN_PASSWORD from .env.
 * Part of DatabaseSeeder; alone it gives a database without any business data — the empty states of every page
 * (make visual-empty). Re-running keeps existing users (and their passwords) unchanged.
 */
class UsersSeeder extends Seeder
{
    /** Synthetic people: first letters give the avatar initials. */
    private const NAMES = [
        'director' => 'Демо Директор', 'manager' => 'Демо Продажи', 'storekeeper' => 'Демо Склад',
        'foreman' => 'Демо Цех', 'accountant' => 'Демо Бухгалтер', 'hr' => 'Демо Кадры', 'buyer' => 'Демо Закупщик',
        'salon_armada' => 'Демо Армада', 'salon_nazarbayeva' => 'Демо Назарбаева', 'salon_armada_mgr' => 'Демо Салоны',
        'dealer_astana' => 'Демо Астана', 'dealer_aktau' => 'Демо Актау', 'dealer_shymkent' => 'Демо Шымкент',
        'dealer_atyrau' => 'Демо Атырау', 'dealer_aktobe' => 'Демо Актобе',
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
    }
}
