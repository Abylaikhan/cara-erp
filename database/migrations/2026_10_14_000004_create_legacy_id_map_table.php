<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Legacy id → new id for every migrated record (ТЗ §3.3, MIG-02): history stays traceable after migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_key', 128);
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('snapshot_id', 32);
            $table->timestampTz('imported_at')->useCurrent();
            $table->unique(['source_table', 'source_key']);
            $table->index(['target_table', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
