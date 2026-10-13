<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M08 «Покупатели» (ERP-026). Legacy: customers(id uuid, created_at, name, type, inn, phone, email, address, contact, comment,
// channel, turnover). `contact` is empty and `turnover` is 0 in every legacy row: turnover is computed from orders, not stored.
// Duplicate names are separate records (DATA-MAP §3: never merge only by name), so there is no unique name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            // Legacy values: «Юр. лицо»/«Физ. лицо» from the form; «client»/«company» in older rows (kept as they are).
            $table->string('type', 16);
            $table->string('inn', 12)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('comment')->nullable();
            $table->string('channel', 64);
            $table->foreign('channel')->references('code')->on('sales_channels')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['channel', 'name']);
        });
        // Sorted like the legacy database (docs/adr/0005-catalog.md, decision 5).
        DB::statement('ALTER TABLE customers ALTER COLUMN name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_known CHECK (type IN ('Юр. лицо', 'Физ. лицо', 'client', 'company'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_inn_12_digits CHECK (inn IS NULL OR inn ~ '^[0-9]{12}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
