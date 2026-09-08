<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_workspace_settings')) {
            Schema::create('ad_workspace_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('autopilot_mode')->default('shadow'); // shadow|approve|auto
                $table->string('active_preset')->nullable(); // protect_profit|balanced|scale
                $table->boolean('kill_switch')->default(false);
                $table->boolean('write_enabled')->default(false);
                $table->unsignedInteger('write_failures')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique('workspace_id');
            });
        }

        if (! Schema::hasTable('ad_rule_packs')) {
            Schema::create('ad_rule_packs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('preset_key'); // protect_profit|balanced|scale|custom
                $table->string('name');
                $table->boolean('enabled')->default(true);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'preset_key']);
            });
        }

        if (! Schema::hasTable('ad_rules')) {
            Schema::create('ad_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ad_rule_pack_id')->nullable()->constrained('ad_rule_packs')->nullOnDelete();
                $table->string('code');
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('lookback_days')->default(14);
                $table->decimal('min_spend', 20, 6)->default(0);
                $table->unsignedInteger('min_clicks')->default(0);
                $table->json('condition_json');
                $table->json('action_json');
                $table->boolean('enabled')->default(true);
                $table->boolean('auto_execute')->default(false);
                $table->unsignedSmallInteger('priority')->default(100);
                $table->timestamps();

                $table->unique(['workspace_id', 'code']);
                $table->index(['workspace_id', 'enabled', 'priority']);
            });
        }

        if (! Schema::hasTable('ad_action_proposals')) {
            Schema::create('ad_action_proposals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('ad_rule_id')->nullable()->constrained('ad_rules')->nullOnDelete();
                $table->string('status')->default('pending'); // pending|approved|rejected|executed|expired|failed
                $table->string('entity_type'); // campaign|ad|item
                $table->string('entity_id');
                $table->string('ml_item_id')->nullable();
                $table->string('action_type');
                $table->json('action_payload')->nullable();
                $table->string('title');
                $table->text('reason');
                $table->string('priority')->default('medium'); // high|medium|low
                $table->decimal('estimated_impact_amount', 20, 6)->nullable();
                $table->json('metrics_snapshot')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'status', 'priority']);
                $table->index(['workspace_id', 'entity_type', 'entity_id']);
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
                $table->string('actor')->default('system'); // user|system
                $table->string('status')->default('executed'); // executed|failed|undone|simulated
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_action_executions');
        Schema::dropIfExists('ad_action_proposals');
        Schema::dropIfExists('ad_rules');
        Schema::dropIfExists('ad_rule_packs');
        Schema::dropIfExists('ad_workspace_settings');
    }
};
