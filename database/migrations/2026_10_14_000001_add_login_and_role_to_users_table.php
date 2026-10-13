<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Legacy users sign in with a login (role key or app_users.login), not an e-mail (doLogin, L1695).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 64)->nullable()->after('id');
            $table->string('role', 64)->nullable()->after('login');
            $table->string('channel', 64)->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('channel');
            $table->string('email')->nullable()->change();
        });

        DB::table('users')->whereNull('login')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'login' => strtolower(strstr((string) $user->email, '@', true) ?: 'user'.$user->id),
                'role' => 'director',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 64)->nullable(false)->change();
            $table->unique('login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login']);
            $table->dropColumn(['login', 'role', 'channel', 'is_active']);
        });
    }
};
