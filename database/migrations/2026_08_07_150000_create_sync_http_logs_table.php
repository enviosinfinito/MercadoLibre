<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_http_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('direction', 8)->default('out'); // in|out
            $table->string('correlation_id', 64)->nullable();
            $table->string('method', 16)->nullable();
            $table->text('url')->nullable();
            $table->string('endpoint_group')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('request_bytes')->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->json('request_headers_redacted')->nullable();
            $table->longText('request_body_redacted')->nullable();
            $table->json('response_headers_redacted')->nullable();
            $table->longText('response_body_redacted')->nullable();
            $table->text('error_redacted')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['connection_id', 'created_at']);
            $table->index(['sync_run_id']);
            $table->index(['correlation_id']);
            $table->index(['direction', 'created_at']);
            $table->index(['response_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_http_logs');
    }
};
