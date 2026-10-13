<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M06 «Объединить» (legacy mergeSupplierGroup, L18062). Legacy deletes the duplicates; here they stay deactivated with a link
// to the kept supplier, and every merge keeps the reference counts before and after (ТЗ M06, AC-58).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->jsonb('merged_ids');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('filled_fields');
            $table->jsonb('references_before');
            $table->jsonb('references_after');
            $table->timestampTz('created_at', 6)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_merges');
    }
};
