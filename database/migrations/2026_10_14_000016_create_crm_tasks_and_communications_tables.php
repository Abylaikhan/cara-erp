<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M10 CRM, part 2 (ERP-037). Legacy tasks(id uuid, created_at, title, date, time, manager, priority, status, lead_id); a task
// «deleted» there is a status, here it is deactivated. The communication history of a lead exists in legacy only in page memory
// (with built-in demo entries), so lead_communications is new: ТЗ M10 asks for it to survive a reload.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->date('due_date')->nullable();
            $table->time('due_time', 0)->nullable();
            $table->string('manager', 255)->nullable();
            $table->string('priority', 8)->default('normal');
            $table->string('status', 8)->default('open');
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'status']);
            $table->index('lead_id');
        });
        DB::statement("ALTER TABLE crm_tasks ADD CONSTRAINT crm_tasks_title_not_blank CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE crm_tasks ADD CONSTRAINT crm_tasks_priority_known CHECK (priority IN ('high', 'normal', 'low'))");
        DB::statement("ALTER TABLE crm_tasks ADD CONSTRAINT crm_tasks_status_known CHECK (status IN ('open', 'done'))");

        Schema::create('lead_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->string('type', 16);
            $table->date('happened_on')->nullable();
            $table->text('text');
            $table->string('manager', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->timestampsTz(6);
            $table->index(['lead_id', 'active']);
        });
        DB::statement("ALTER TABLE lead_communications ADD CONSTRAINT lead_communications_text_not_blank CHECK (btrim(text) <> '')");
        DB::statement("ALTER TABLE lead_communications ADD CONSTRAINT lead_communications_type_known CHECK (type IN ('call', 'letter', 'meeting', 'whatsapp', 'email', 'note'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_communications');
        Schema::dropIfExists('crm_tasks');
    }
};
