<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M10 CRM «Проекты» (ERP-079, legacy c917d37 L31099–31750): requests for a calculation of non-standard furniture. Legacy tables
// projects, project_calcs, project_files, project_comments (columns as the legacy code writes them; the tables were empty in the
// snapshot). The executors are logins, as legacy keeps them in projects.responsible; files are kept by the server, not in a
// public bucket.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('num', 32)->nullable()->unique();
            $table->text('title');
            $table->string('channel', 64);
            $table->foreign('channel')->references('code')->on('sales_channels')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 16)->default('request');
            $table->text('customer_name')->nullable();
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->jsonb('executors')->default('[]');
            $table->text('responsible_name')->nullable();
            $table->string('order_num', 64)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_login', 64)->nullable();
            $table->string('created_by_name')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->index(['active', 'channel']);
        });
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_title_not_blank CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_known CHECK (status IN ('request', 'calc', 'sent', 'approved', 'order', 'closed'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_executors_array CHECK (jsonb_typeof(executors) = 'array')");

        Schema::create('project_calcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('items');
            $table->decimal('total', 18, 2);
            $table->decimal('markup_pct', 9, 2)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_login', 64)->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestampsTz(6);
            $table->unique(['project_id', 'version']);
        });
        DB::statement("ALTER TABLE project_calcs ADD CONSTRAINT project_calcs_items_array CHECK (jsonb_typeof(items) = 'array')");
        DB::statement('ALTER TABLE project_calcs ADD CONSTRAINT project_calcs_version_positive CHECK (version > 0)');

        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('side', 16);
            $table->boolean('internal')->default(false);
            $table->string('file_name', 255);
            $table->string('storage_path', 500)->unique();
            $table->string('mime', 255)->default('');
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_login', 64)->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestampsTz(6);
            $table->index('project_id');
        });
        DB::statement("ALTER TABLE project_files ADD CONSTRAINT project_files_side_known CHECK (side IN ('customer', 'designer'))");

        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('side', 16);
            $table->boolean('internal')->default(false);
            $table->text('text');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_login', 64)->nullable();
            $table->string('author_name')->nullable();
            $table->timestampsTz(6);
            $table->index('project_id');
        });
        DB::statement("ALTER TABLE project_comments ADD CONSTRAINT project_comments_side_known CHECK (side IN ('client', 'staff'))");
        DB::statement("ALTER TABLE project_comments ADD CONSTRAINT project_comments_text_not_blank CHECK (btrim(text) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comments');
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('project_calcs');
        Schema::dropIfExists('projects');
    }
};
