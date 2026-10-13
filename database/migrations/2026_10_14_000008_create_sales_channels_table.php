<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M13 «Центры прибыли» / channels (ERP-023). Legacy keeps them in code: SALES_CHANNELS and CH_GROUPS (L15213–15228),
// dealer regions in dealersData (L19003). Codes are the legacy ids: they are stored in orders, customers, leads and users.
return new class extends Migration
{
    private const CHANNELS = [
        ['code' => 'office', 'name' => 'Офис (прямые продажи)', 'icon' => '🏢', 'channel_group' => 'direct', 'region' => null],
        ['code' => 'salon_armada', 'name' => 'Салон Армада', 'icon' => '🏬', 'channel_group' => 'salon', 'region' => null],
        ['code' => 'salon_nazarbayeva', 'name' => 'Салон Назарбаева', 'icon' => '🏪', 'channel_group' => 'salon', 'region' => null],
        ['code' => 'dealer_astana', 'name' => 'Дилер Астана', 'icon' => '📍', 'channel_group' => 'dealer', 'region' => 'Астана'],
        ['code' => 'dealer_aktau', 'name' => 'Дилер Актау', 'icon' => '📍', 'channel_group' => 'dealer', 'region' => 'Актау'],
        ['code' => 'dealer_shymkent', 'name' => 'Дилер Шымкент', 'icon' => '📍', 'channel_group' => 'dealer', 'region' => 'Шымкент'],
        ['code' => 'dealer_atyrau', 'name' => 'Дилер Атырау', 'icon' => '📍', 'channel_group' => 'dealer', 'region' => 'Атырау'],
        ['code' => 'dealer_aktobe', 'name' => 'Дилер Актобе', 'icon' => '📍', 'channel_group' => 'dealer', 'region' => 'Актобе'],
    ];

    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('name', 100);
            $table->string('icon', 16);
            $table->string('channel_group', 16);
            $table->string('region', 100)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('active')->default(true);
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE sales_channels ADD CONSTRAINT sales_channels_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement("ALTER TABLE sales_channels ADD CONSTRAINT sales_channels_group_known CHECK (channel_group IN ('direct', 'salon', 'dealer'))");

        $now = now();
        DB::table('sales_channels')->insert(array_map(
            fn (array $c, int $i) => $c + ['sort_order' => $i + 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            self::CHANNELS, array_keys(self::CHANNELS),
        ));

        // A user's channel (salon or dealer accounts) must be a known channel.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('channel')->references('code')->on('sales_channels')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['channel']);
        });
        Schema::dropIfExists('sales_channels');
    }
};
