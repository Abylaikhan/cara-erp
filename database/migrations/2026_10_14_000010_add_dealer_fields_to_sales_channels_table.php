<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M08 «Покупатели», tab «Дилеры» (ERP-026). Legacy keeps dealers only in the page memory (dealersData, QA-042); here a dealer
// is a channel of the group «dealer»: author, deactivation and lock_version for inline edits. Legacy shows no dealer status
// (the column shows the order status filter), so none is stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->timestampTz('deactivated_at', 6)->nullable()->after('active');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropConstrainedForeignId('updated_by_id');
            $table->dropColumn(['deactivated_at', 'created_by_name', 'updated_by_name', 'lock_version']);
        });
    }
};
