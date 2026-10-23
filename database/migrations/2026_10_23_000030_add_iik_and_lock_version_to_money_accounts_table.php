<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Деньги» → the window «Расчётный счёт» saves (answer of the accountant 23.10, № 37): the account number the form asks for, and
// the version of the record — two people editing an account at once must not overwrite each other unseen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->string('iik', 34)->nullable()->after('bik');
            $table->unsignedInteger('lock_version')->default(1)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->dropColumn(['iik', 'lock_version']);
        });
    }
};
