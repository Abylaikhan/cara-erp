<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Финансы» → «📊 P&L» → «Выручка по направлениям» (ADR-0052): legacy finds the channel of a realization without an order by the
// name of its customer (CUSTOMER_NAME_TO_CHANNEL in the code of its page). The names are real — kept here, carried over by
// `legacy:import channel_customer_names`, not in the code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_customer_names', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('channel', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_customer_names');
    }
};
