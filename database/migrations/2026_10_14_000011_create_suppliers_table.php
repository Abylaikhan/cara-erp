<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M06 «Поставщики» (ERP-025). Legacy: suppliers(id uuid, created_at, name, category, phone, email, contact, inn, comment, status,
// bin, expense_category, expense_subcategory, expense_source). The card also asks for address, lead time, yearly turnover and
// defect share, which legacy keeps only in page memory (saveSupplierCard, localExtra) — here they are stored.
// The expense item is one link: the article, or the top-level category when no article is chosen (legacy stores both codes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('category', 100)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('contact', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('bin', 32)->nullable()->index();
            $table->string('inn', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('turnover', 18, 2)->nullable();
            $table->decimal('defect_pct', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->string('expense_source', 16)->default('payment');
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'name']);
        });
        // Sorted like the legacy database (docs/adr/0005-catalog.md, decision 5).
        DB::statement('ALTER TABLE suppliers ALTER COLUMN name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_known CHECK (status IN ('active', 'pause', 'blocked'))");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_expense_source_known CHECK (expense_source IN ('payment', 'esf'))");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_numbers_not_negative CHECK ((turnover IS NULL OR turnover >= 0) AND (defect_pct IS NULL OR defect_pct BETWEEN 0 AND 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
