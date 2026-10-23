<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Сотрудники» → «Пользователи» (D-13): an account is edited by the director or HR; two of them editing it at once must not
// overwrite each other unseen (the version of the record, as everywhere).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('pages');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
