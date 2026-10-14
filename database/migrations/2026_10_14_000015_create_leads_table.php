<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M10 CRM, leads (ERP-036). Legacy: leads(id uuid, created_at, name, phone, status, interest, budget, source, comment, channel,
// manager). Segment, tags and the next action are asked by the legacy funnel form but kept only in page memory; ТЗ M10 lists
// them as lead fields, so they are stored here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('phone', 64)->nullable();
            $table->string('status', 16)->default('new');
            $table->string('interest', 500)->nullable();
            $table->decimal('budget', 18, 2)->default(0);
            $table->string('source', 32)->nullable();
            $table->text('comment')->nullable();
            $table->string('channel', 64);
            $table->foreign('channel')->references('code')->on('sales_channels')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('manager', 255)->nullable();
            $table->unsignedSmallInteger('segment')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->date('next_date')->nullable();
            $table->string('next_action', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'channel', 'status']);
            $table->index('created_at');
        });
        DB::statement('ALTER TABLE leads ALTER COLUMN name TYPE varchar(255) COLLATE "en-US-x-icu"');
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_status_known CHECK (status IN ('new', 'contact', 'meeting', 'qualify', 'proposal', 'negotiation', 'won', 'lost'))");
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_budget_not_negative CHECK (budget >= 0)');
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_tags_array CHECK (jsonb_typeof(tags) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
