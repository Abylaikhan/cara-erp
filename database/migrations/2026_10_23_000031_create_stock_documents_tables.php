<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «Склад» → «Документы»: the receipt of goods (answer of the accountant 23.10, № 45, D-04 — ADR-0049). A document with its
// lines; posting it moves the stock, and every move is a line of the journal of movements (one fact — one movement). Legacy
// kept its documents in the page and changed the stock only on the screen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 16);
            $table->string('num', 32);
            $table->date('doc_date');
            $table->string('status', 8)->default('draft');
            $table->string('supplier_name', 255)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('esf_ref', 64)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestampTz('posted_at', 6)->nullable();
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->unique(['doc_type', 'num']);
            $table->index(['doc_date', 'id']);
        });
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_known CHECK (doc_type IN ('receipt'))");
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_status_known CHECK (status IN ('draft', 'posted'))");
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_num_not_blank CHECK (btrim(num) <> '')");

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 18, 3);
            $table->string('unit', 16);
            $table->decimal('price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->unique(['document_id', 'line_no']);
        });
        DB::statement('ALTER TABLE stock_document_lines ADD CONSTRAINT stock_document_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE stock_document_lines ADD CONSTRAINT stock_document_lines_price_not_negative CHECK (price >= 0)');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('stock_document_lines')->restrictOnDelete();
            $table->date('moved_on');
            $table->decimal('qty_delta', 18, 3);
            $table->decimal('qty_after', 18, 3);
            // № 46: the stock may go below zero, marked (a write-off over the stock — with the write-offs).
            $table->boolean('below_zero')->default(false);
            $table->timestampTz('created_at', 6);
            $table->index(['material_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
    }
};
