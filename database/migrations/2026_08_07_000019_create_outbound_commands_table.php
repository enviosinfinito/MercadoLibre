<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('command_type');
            $table->string('status')->default('pending');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error_redacted')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'outbound_commands_workspace_idempotency_unique');
            $table->index(['connection_id', 'status', 'available_at']);
            $table->index(['workspace_id', 'command_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_commands');
    }
};
