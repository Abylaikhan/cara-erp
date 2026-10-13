<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Legacy lists come ordered by the Supabase database (glibc en_US.UTF-8). Our PostgreSQL image (musl) sorts by bytes:
// 716 of 1652 catalog positions came out in a different order. ICU "en-US" reproduces the legacy order exactly for all
// distinct values (measured on the read-only copy, docs/adr/0005-catalog.md). Sortable text columns use it.
return new class extends Migration
{
    private const COLUMNS = [
        'products' => ['name' => 'varchar(255)', 'category' => 'varchar(100)'],
        'expense_categories' => ['name' => 'varchar(255)'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE {$type} COLLATE \"en-US-x-icu\"");
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE {$type} COLLATE \"default\"");
            }
        }
    }
};
