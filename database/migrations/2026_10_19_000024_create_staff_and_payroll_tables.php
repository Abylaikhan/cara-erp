<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M17 «Сотрудники» and the payroll records of M18–M21 as legacy keeps them (staff, staff_salary_history, staff_changes,
// salary_accruals, salary_accruals_history, wage_items, salon_payroll). The data are carried over as they are; how they are
// calculated and changed waits for D-06 and D-13. Every row keeps its legacy uuid. Amounts are stored exactly as entered
// (legacy computes some in the browser: up to 15 decimals). Payroll records name the employee by text, as legacy does.
// Numbers of an employee may be empty: legacy then shows its own defaults (10 % ОПВ, 3 % ОСМС, 400 pieces…).
// No PIN is stored: PINs are not copied (ADR-0004) and are issued anew with the employee cabinet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 255);
            $table->string('position', 255)->nullable();
            $table->string('type', 16)->nullable();         // aup, office, prod
            $table->string('workshop', 64)->nullable();     // legacy ws
            $table->string('status', 32)->nullable();
            $table->boolean('active')->default(true);
            $table->date('hired')->nullable();
            $table->date('fired')->nullable();           // legacy «Уволить» writes the day here
            $table->date('fired_date')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('iin', 32)->nullable();
            $table->decimal('salary', 30, 12)->nullable();
            $table->decimal('base_salary', 30, 12)->nullable();
            $table->string('commission_method', 32)->nullable();
            $table->decimal('commission_pct', 30, 12)->nullable();
            $table->decimal('deduction_meal', 30, 12)->nullable();
            $table->decimal('deduction_opv_fixed', 30, 12)->nullable();
            $table->decimal('deduction_opv_pct', 30, 12)->nullable();
            $table->decimal('deduction_osms_fixed', 30, 12)->nullable();
            $table->decimal('deduction_osms_pct', 30, 12)->nullable();
            $table->decimal('deduction_so_pct', 30, 12)->nullable();
            $table->decimal('piece_goal', 30, 12)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE employees ALTER COLUMN name TYPE varchar(255) COLLATE "en-US-x-icu"');

        Schema::create('employee_salary_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('salary', 30, 12)->nullable();
            $table->decimal('commission_pct', 30, 12)->nullable();
            $table->date('valid_from');
            $table->text('note')->nullable();
            $table->timestampsTz(6);
            $table->index(['employee_id', 'valid_from']);
        });

        Schema::create('employee_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('change_date');
            $table->string('change_type', 32);
            $table->decimal('base_salary', 30, 12)->nullable();
            $table->decimal('deduction_meal', 30, 12)->nullable();
            $table->decimal('deduction_opv_pct', 30, 12)->nullable();
            $table->decimal('deduction_osms_pct', 30, 12)->nullable();
            $table->decimal('deduction_so_pct', 30, 12)->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz(6);
            $table->index(['employee_id', 'change_date']);
        });

        Schema::create('salary_accruals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('emp_name', 255);
            $table->string('type', 16);                     // salary (начисление), payment (выплата)
            $table->date('accrual_date');
            $table->decimal('amount', 30, 15);
            $table->decimal('deducted', 30, 15);
            $table->decimal('net', 30, 15);
            $table->jsonb('details')->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz(6);
            $table->index(['emp_name', 'accrual_date']);
            $table->index('accrual_date');
        });
        DB::statement('ALTER TABLE salary_accruals ALTER COLUMN emp_name TYPE varchar(255) COLLATE "en-US-x-icu"');

        Schema::create('salary_accrual_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('accrual_uuid');
            $table->foreignId('salary_accrual_id')->nullable()->constrained('salary_accruals')->nullOnDelete();
            $table->string('emp_name', 255);
            $table->date('accrual_date');
            $table->decimal('amount', 30, 15);
            $table->decimal('deducted', 30, 15);
            $table->decimal('net', 30, 15);
            $table->jsonb('details')->nullable();
            $table->text('comment')->nullable();
            $table->string('changed_by', 64)->nullable();
            $table->timestampTz('changed_at', 6);
            $table->timestampsTz(6);
        });

        Schema::create('piece_work_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('work_date', 32);                // as typed: almost always YYYY-MM-DD, a few free texts
            $table->string('emp_name', 255);
            $table->string('workshop', 64);
            $table->string('operation', 64);
            $table->string('order_num', 64);
            $table->decimal('qty', 30, 12);
            $table->decimal('rate', 30, 12);
            $table->timestampsTz(6);
            $table->index(['emp_name', 'work_date']);
        });
        DB::statement('ALTER TABLE piece_work_items ALTER COLUMN emp_name TYPE varchar(255) COLLATE "en-US-x-icu"');

        Schema::create('salon_payroll_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('employee', 255);
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->decimal('salary', 30, 12);
            $table->decimal('commission', 30, 12);
            $table->decimal('bonus', 30, 12);
            $table->decimal('vacation', 30, 12);
            $table->decimal('advance', 30, 12);
            $table->decimal('total', 30, 12);
            $table->decimal('remainder', 30, 12);
            $table->timestampsTz(6);
            $table->index(['year', 'month']);
        });
        DB::statement('ALTER TABLE salon_payroll_rows ADD CONSTRAINT salon_payroll_rows_month CHECK (month BETWEEN 1 AND 12)');
    }

    public function down(): void
    {
        foreach (['salon_payroll_rows', 'piece_work_items', 'salary_accrual_changes', 'salary_accruals', 'employee_changes', 'employee_salary_history', 'employees'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
