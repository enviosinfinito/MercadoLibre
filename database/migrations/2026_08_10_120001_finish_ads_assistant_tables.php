<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes assistant tables if the previous migration partially applied
 * (e.g. MySQL created settings/rules/proposals but not executions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_action_proposals') && Schema::hasColumn('ad_action_proposals', 'severity') && ! Schema::hasColumn('ad_action_proposals', 'priority')) {
            Schema::table('ad_action_proposals', function (Blueprint $table) {
                $table->renameColumn('severity', 'priority');
            });
        }

        if (! Schema::hasTable('ad_action_executions')) {
            Schema::create('ad_action_executions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('ad_action_proposal_id')->nullable()->constrained('ad_action_proposals')->nullOnDelete();
                $table->foreignId('ad_rule_id')->nullable()->constrained('ad_rules')->nullOnDelete();
                $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor')->default('system');
                $table->string('status')->default('executed');
                $table->string('entity_type');
                $table->string('entity_id');
                $table->string('ml_item_id')->nullable();
                $table->string('action_type');
                $table->json('before_payload')->nullable();
                $table->json('after_payload')->nullable();
                $table->json('undo_payload')->nullable();
                $table->text('reason')->nullable();
                $table->text('error_redacted')->nullable();
                $table->boolean('wrote_to_ml')->default(false);
                $table->timestamp('undone_at')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'created_at']);
                $table->index(['workspace_id', 'entity_type', 'entity_id']);
            });
        }

        if (! Schema::hasTable('ad_workspace_settings')) {
            Schema::create('ad_workspace_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('autopilot_mode')->default('shadow');
                $table->string('active_preset')->nullable();
                $table->boolean('kill_switch')->default(false);
                $table->boolean('write_enabled')->default(false);
                $table->unsignedInteger('write_failures')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique('workspace_id');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive down: keep data; only drop executions if this migration created them alone.
    }
};
