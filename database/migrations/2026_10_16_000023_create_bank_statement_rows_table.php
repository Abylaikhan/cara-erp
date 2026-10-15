<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M12 «Деньги», «Сверка с банком» (legacy bank_statement_raw, L9346–9612): the bank statements kept apart from the journal;
// a row goes to the journal only when it is transferred (that posts money and waits for ERP-031…034). Rows keep the legacy uuid.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->string('bank_format', 16);
            $table->date('row_date')->nullable();
            $table->string('flow', 8);
            $table->decimal('amount', 18, 2);
            $table->string('counterparty', 255)->nullable();
            $table->string('bin', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('knp', 16)->nullable();
            $table->string('doc_num', 64)->nullable();
            $table->boolean('transferred_to_db')->default(false);
            $table->foreignId('transaction_id')->nullable()->constrained('money_transactions')->nullOnDelete();
            $table->timestampTz('transferred_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['account_id', 'row_date']);
        });
        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_flow_known CHECK (flow IN ('income', 'expense'))");
        DB::statement('ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_amount_positive CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_rows');
    }
};
