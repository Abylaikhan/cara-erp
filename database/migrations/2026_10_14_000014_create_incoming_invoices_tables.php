<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M05 «Входящие ЭСФ» (ERP-047). Legacy: incoming_invoices(id uuid, reg_num, num, date, status, supplier_name, supplier_bin,
// buyer_name, buyer_bin, currency, total_amount, total_nds, total_with_nds, created_at, posted_at, invoice_type),
// incoming_invoice_items(id uuid, invoice_id, description, qty, unit_price, amount, nds_rate, nds_amount, total, warehouse_id),
// esf_payment_links(id uuid, esf_invoice_id, source_type, source_id, amount, created_at).
// Amounts may be negative: a correcting ESF reverses an earlier one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('num', 64)->nullable();
            // Registration number in the IS ESF: one invoice is imported once.
            $table->string('reg_num', 64)->nullable()->unique();
            $table->date('date');
            $table->string('status', 64);
            $table->string('invoice_type', 16)->default('general');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_name', 255);
            $table->string('supplier_bin', 32)->nullable()->index();
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_bin', 32)->nullable();
            $table->string('currency', 3)->default('KZT');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('total_nds', 18, 2);
            $table->decimal('total_with_nds', 18, 2);
            $table->timestampTz('posted_at', 6)->nullable();
            $table->string('source_file', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['date', 'created_at']);
        });
        DB::statement('ALTER TABLE incoming_invoices ALTER COLUMN supplier_name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE incoming_invoices ADD CONSTRAINT incoming_invoices_type_known CHECK (invoice_type IN ('general', 'service', 'goods'))");

        Schema::create('incoming_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('incoming_invoices')->cascadeOnDelete();
            // Order of the lines as in the ESF (legacy shows them in insertion order).
            $table->unsignedInteger('line_no');
            $table->text('description');
            $table->decimal('qty', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('nds_rate', 5, 2);
            $table->decimal('nds_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->string('warehouse_code', 64)->nullable();
            $table->foreign('warehouse_code')->references('code')->on('warehouses')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['invoice_id', 'line_no']);
        });

        Schema::create('esf_payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('incoming_invoices')->cascadeOnDelete();
            // A payment of «Деньги» (money_tx) or an advance report. Those modules are not migrated yet: the legacy id is kept
            // and resolved through legacy_id_map when they are (ERP-031/035).
            $table->string('source_type', 32);
            $table->string('source_legacy_id', 64)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestampTz('created_at', 6);
            $table->index(['source_type', 'source_legacy_id']);
        });
        DB::statement("ALTER TABLE esf_payment_links ADD CONSTRAINT esf_payment_links_source_known CHECK (source_type IN ('money_tx', 'advance_report'))");
        DB::statement('ALTER TABLE esf_payment_links ADD CONSTRAINT esf_payment_links_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('esf_payment_links');
        Schema::dropIfExists('incoming_invoice_items');
        Schema::dropIfExists('incoming_invoices');
    }
};
