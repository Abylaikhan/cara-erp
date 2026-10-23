<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Входящие ЭСФ» → «📦 Принять» / «📦 На склад» (№ 45 — ADR-0049, part 2): the line of the receipt keeps the ESF line it came
// from; one ESF line is taken in once (legacy added its quantity again on every click).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->foreignId('esf_item_id')->nullable()->unique()->after('material_id')->constrained('incoming_invoice_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('esf_item_id');
        });
    }
};
