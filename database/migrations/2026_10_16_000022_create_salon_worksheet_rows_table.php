<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M12 «Деньги», «Рабочий лист салонов» (legacy salon_worksheet, L8832–9330): the cash book of a salon as the salon keeps it —
// accepted orders, payments, expenses, money handed to the office. It does not move money until a row is transferred; the
// transfer posts operations and waits for ERP-031…033. Rows keep the legacy uuid: the running balance orders rows of one date
// by it, as legacy does.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_worksheet_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('salon', 16);
            $table->date('order_date')->nullable();
            $table->string('order_num', 64)->nullable();
            $table->string('manager', 255)->nullable();
            $table->decimal('balance_before', 30, 12)->nullable();
            $table->date('payment_date')->nullable();
            $table->decimal('cash_amount', 30, 12)->nullable();
            $table->decimal('card_amount', 30, 12)->nullable();
            $table->decimal('balance_after', 30, 12)->nullable();
            $table->text('note')->nullable();
            $table->date('delivery_date')->nullable();
            $table->decimal('cod_amount', 30, 12)->nullable();
            $table->decimal('expense_amount', 30, 12)->nullable();
            $table->decimal('transferred_to_office', 30, 12)->nullable();
            $table->text('recipient_desc')->nullable();
            $table->boolean('transferred_to_db')->default(false);
            $table->timestampTz('transferred_at', 6)->nullable();
            $table->jsonb('transferred_tx_ids')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->timestampsTz(6);
            $table->index(['salon', 'order_date']);
        });
        DB::statement("ALTER TABLE salon_worksheet_rows ADD CONSTRAINT salon_worksheet_rows_salon_known CHECK (salon IN ('armada', 'nazarbayeva'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_worksheet_rows');
    }
};
