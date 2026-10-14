<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M12 «Деньги», часть 1 (ERP-031, view only). Legacy keeps the cash desks and bank accounts in the page code (bankAccounts)
// and the operations in money_tx(id, date, account_id, flow, category, subcategory = expense item code, amount, amount_fx,
// fx_currency, description, counterparty, bin, order_num, created_at); order_payments split one income over several orders;
// advance_reports are the accountable advances. Account numbers (IBAN) are not stored. Posting, editing and the rules of
// balances and payment shares come with ERP-031…035 after decisions D-03 and D-11.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('bank', 255)->nullable();
            $table->string('bik', 16)->nullable();
            $table->string('currency', 3)->default('KZT');
            $table->string('type', 8);
            $table->string('icon', 16)->nullable();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_type_known CHECK (type IN ('cash', 'bank'))");

        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('tx_date');
            $table->string('flow', 8);
            $table->string('category', 32)->nullable();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_fx', 18, 2)->nullable();
            $table->string('fx_currency', 3)->nullable();
            $table->text('description')->nullable();
            $table->string('counterparty', 255)->nullable();
            $table->string('bin', 12)->nullable();
            $table->string('order_num', 32)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->timestampsTz(6);
            $table->index(['account_id', 'tx_date']);
            $table->index('tx_date');
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE money_transactions ADD CONSTRAINT money_transactions_flow_known CHECK (flow IN ('income', 'expense'))");
        DB::statement('ALTER TABLE money_transactions ADD CONSTRAINT money_transactions_amount_not_negative CHECK (amount >= 0)');

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('money_transactions')->restrictOnDelete();
            $table->string('order_num', 32);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestampsTz(6);
        });

        Schema::create('advance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('doc_num', 32)->nullable();
            $table->string('employee_name', 255);
            $table->date('report_date');
            $table->text('purpose')->nullable();
            // Legacy sums the lines in floating point and stores the artefacts (up to 11 decimals): kept exactly.
            $table->decimal('issued', 30, 12)->default(0);
            $table->decimal('spent', 30, 12)->default(0);
            $table->decimal('returned', 30, 12)->default(0);
            $table->string('status', 16)->default('open');
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('money_transactions')->nullOnDelete();
            $table->jsonb('lines')->default('[]');
            $table->timestampsTz(6);
            $table->index('report_date');
        });
        DB::statement("ALTER TABLE advance_reports ADD CONSTRAINT advance_reports_status_known CHECK (status IN ('open', 'reported', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_reports');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('money_transactions');
        Schema::dropIfExists('money_accounts');
    }
};
