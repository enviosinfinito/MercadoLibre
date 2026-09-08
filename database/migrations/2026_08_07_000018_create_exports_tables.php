<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('report_type');
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['workspace_id', 'report_type']);
        });

        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('saved_report_view_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type');
            $table->string('format')->default('xlsx');
            $table->string('status')->default('pending');
            $table->json('filters')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();
            $table->text('error_redacted')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('scheduled_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_view_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('report_type');
            $table->string('format')->default('xlsx');
            $table->string('cron_expression');
            $table->string('timezone')->default('America/Mexico_City');
            $table->boolean('is_active')->default(true);
            $table->json('delivery')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_exports');
        Schema::dropIfExists('export_runs');
        Schema::dropIfExists('saved_report_views');
    }
};
