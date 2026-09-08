<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('topic')->nullable();
            $table->string('external_user_id')->nullable();
            $table->string('external_resource_id')->nullable();
            $table->string('dedupe_key')->unique();
            $table->json('payload');
            $table->json('headers_redacted')->nullable();
            $table->string('status')->default('received');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'received_at']);
            $table->index(['connection_id', 'topic', 'received_at']);
            $table->index(['provider', 'external_user_id']);
        });

        Schema::create('raw_resource_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('external_id');
            $table->json('payload');
            $table->string('checksum')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['connection_id', 'resource_type', 'external_id'], 'raw_snapshots_conn_type_ext_idx');
            $table->index(['workspace_id', 'fetched_at']);
        });

        Schema::create('canonical_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('aggregate_type')->nullable();
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'event_type', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error_redacted')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('projection_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('projection_name');
            $table->unsignedBigInteger('version')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'projection_name']);
        });

        Schema::create('processing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status');
            $table->text('error_redacted')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'attempt_number'], 'processing_attempts_subject_attempt_index');
            $table->index(['workspace_id', 'status', 'attempted_at']);
        });

        Schema::create('dead_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('queue')->nullable();
            $table->string('job_class')->nullable();
            $table->json('payload')->nullable();
            $table->text('error_redacted')->nullable();
            $table->timestamp('failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'failed_at']);
            $table->index(['resolved_at', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
        Schema::dropIfExists('processing_attempts');
        Schema::dropIfExists('projection_versions');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('canonical_events');
        Schema::dropIfExists('raw_resource_snapshots');
        Schema::dropIfExists('raw_webhook_events');
    }
};
