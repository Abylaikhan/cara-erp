<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «Сотрудники» → «Штатное расписание» becomes a directory the company changes (answer of the accountant 23.10, № 76 — ADR-0051).
// Legacy keeps the departments and positions in the code of its page (staffDepts, staffPositions): they are carried over by
// `legacy:import staff_structure`; a row carried over keeps its legacy key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_departments', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_key', 32)->nullable()->unique();
            $table->string('name', 255);
            $table->string('icon', 16)->default('🏢');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE staff_departments ADD CONSTRAINT staff_departments_name_not_blank CHECK (btrim(name) <> '')");

        Schema::create('staff_positions', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_key', 32)->nullable()->unique();
            $table->foreignId('department_id')->constrained('staff_departments')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('grade', 32)->default('');
            $table->string('wage_type', 16)->default('salary');
            $table->unsignedInteger('salary_min')->default(0);
            $table->unsignedInteger('salary_max')->default(0);
            $table->unsignedSmallInteger('slots')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE staff_positions ADD CONSTRAINT staff_positions_title_not_blank CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE staff_positions ADD CONSTRAINT staff_positions_wage_type_known CHECK (wage_type IN ('salary', 'piecework'))");
        DB::statement('ALTER TABLE staff_positions ADD CONSTRAINT staff_positions_slots_positive CHECK (slots >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_positions');
        Schema::dropIfExists('staff_departments');
    }
};
