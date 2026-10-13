<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Idempotent synthetic development seed. No real employees, clients or legacy passwords.
 * Re-running keeps existing records (and their passwords) unchanged.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The synthetic development seed must not run in production.');
        }

        $email = env('DEV_ADMIN_EMAIL', 'admin@promtech.test');
        $password = env('DEV_ADMIN_PASSWORD');

        if (blank($password)) {
            throw new RuntimeException('DEV_ADMIN_PASSWORD is not set in .env.');
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Администратор (синтетический)', 'password' => Hash::make($password)],
        );
    }
}
