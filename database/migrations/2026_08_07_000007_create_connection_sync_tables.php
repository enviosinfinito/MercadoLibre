<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('capability_key');
            $table->boolean('enabled')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'capability_key'], 'connection_capabilities_connection_key_unique');
            $table->index(['workspace_id', 'capability_key']);
        });

        Schema::create('token_refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedBigInteger('token_generation')->nullable();
            $table->text('error_redacted')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['connection_id', 'attempted_at']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->text('cursor_value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'resource_type'], 'sync_cursors_connection_resource_unique');
            $table->index(['workspace_id', 'resource_type']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('mode')->default('incremental');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_redacted')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['connection_id', 'resource_type', 'created_at']);
        });

        Schema::create('api_call_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->string('endpoint_group')->nullable();
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('rate_limited_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['connection_id', 'window_start', 'window_end', 'endpoint_group'],
                'api_call_stats_connection_window_endpoint_unique'
            );
            $table->index(['workspace_id', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_call_stats');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('sync_cursors');
        Schema::dropIfExists('token_refresh_attempts');
        Schema::dropIfExists('connection_capabilities');
    }
};
