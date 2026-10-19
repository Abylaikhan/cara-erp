<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The salon payroll lines in the order legacy lists them (order=year.desc,month.desc): lines of one month keep the order the
// legacy database returns them in, and the history of an employee shows the last of two lines of one month
// (`legacy:natural-order salon_payroll --order=year.desc,month.desc`). Empty when that order was not read.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_payroll_rows', function (Blueprint $table) {
            $table->unsignedInteger('legacy_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salon_payroll_rows', function (Blueprint $table) {
            $table->dropColumn('legacy_position');
        });
    }
};
