<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_user_id')->nullable();
            $table->string('site_id')->nullable()->comment('Also used as marketplace_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('token_generation')->default(0);
            $table->string('freshness_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error_redacted')->nullable();
            $table->boolean('needs_reauthorization')->default(false);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'provider', 'external_user_id', 'site_id'],
                'connections_workspace_provider_external_site_unique'
            );
            $table->index(['workspace_id', 'provider', 'status']);
            $table->index(['provider', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
