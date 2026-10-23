<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-13 (23.10.2026): the accounts are those legacy has — its built-in roles and the records of app_users laid over them
// (legacy dbUsersApply: name, role label, avatar, colour, pages, blocked). The pages of an account replace those of its role
// when set (the director keeps the pages of the role besides). Passwords and PINs are never copied: new ones are issued.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_label', 128)->nullable()->after('channel');
            $table->string('initials', 8)->nullable()->after('role_label');
            $table->string('color', 16)->nullable()->after('initials');
            $table->jsonb('pages')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role_label', 'initials', 'color', 'pages']);
        });
    }
};
