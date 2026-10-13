<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// timestampsTz() creates timestamp(0): legacy moments (with microseconds) were rounded to the second on import.
return new class extends Migration
{
    private const COLUMNS = [
        'products' => ['created_at', 'updated_at', 'deactivated_at'],
        'expense_categories' => ['created_at', 'updated_at', 'deactivated_at'],
        'expense_category_merges' => ['created_at'],
        'audit_events' => ['occurred_at'],
        'legacy_id_map' => ['imported_at'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamptz(6)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamptz(0)");
            }
        }
    }
};
