<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «Финансы» → «Точка безубыточности» → «Постоянные затраты — план и факт» (legacy table fixed_costs, empty in legacy): the plan of
// a month by article, its day of payment and the mark «✓ Проведено». A month is saved as a whole (ADR-0055).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7);
            $table->string('name', 255);
            $table->string('category', 64);
            $table->decimal('plan', 15, 2)->default(0);
            $table->boolean('approved')->default(false);
            $table->unsignedSmallInteger('pay_day')->nullable();
            $table->timestampsTz(6);
            $table->index('period');
        });
        DB::statement("ALTER TABLE fixed_costs ADD CONSTRAINT fixed_costs_period_month CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        DB::statement("ALTER TABLE fixed_costs ADD CONSTRAINT fixed_costs_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE fixed_costs ADD CONSTRAINT fixed_costs_plan_not_negative CHECK (plan >= 0)');
        DB::statement('ALTER TABLE fixed_costs ADD CONSTRAINT fixed_costs_pay_day_of_month CHECK (pay_day BETWEEN 1 AND 31)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_costs');
    }
};
