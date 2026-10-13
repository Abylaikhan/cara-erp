<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M14 «Статьи расходов». Legacy: expense_categories(code, name, parent_code, is_fixed, sort_order, active).
// Target: stable ids, parent by id, codes stay unique forever (they are referenced by historical operations).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->boolean('is_fixed')->default(false);
            $table->integer('sort_order')->default(50);
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['parent_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE expense_categories ADD CONSTRAINT expense_categories_code_format CHECK (code ~ '^[a-z0-9_]+$')");
        DB::statement('ALTER TABLE expense_categories ADD CONSTRAINT expense_categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('expense_category_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_id')->constrained('expense_categories');
            $table->foreignId('to_id')->constrained('expense_categories');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('references_before');
            $table->jsonb('references_after');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_category_merges');
        Schema::dropIfExists('expense_categories');
    }
};
