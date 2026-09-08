<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedInteger('version')->default(1);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'code', 'version'], 'calculation_versions_workspace_code_version_unique');
        });

        Schema::create('cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty_original', 20, 6);
            $table->decimal('qty_remaining', 20, 6);
            $table->decimal('unit_cost_amount', 20, 6);
            $table->char('unit_cost_currency', 3);
            $table->decimal('fx_rate', 20, 6)->nullable();
            $table->char('fx_from', 3)->nullable();
            $table->char('fx_to', 3)->nullable();
            $table->string('fx_source')->nullable();
            $table->timestamp('fx_dated_at')->nullable();
            $table->decimal('unit_cost_reporting_amount', 20, 6);
            $table->char('reporting_currency', 3)->default('MXN');
            $table->string('source_type')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'variant_id', 'received_at']);
            $table->index(['variant_id', 'warehouse_id', 'qty_remaining']);
        });

        Schema::create('cost_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_layer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost_reporting_amount', 20, 6);
            $table->char('currency_code', 3)->default('MXN');
            $table->decimal('total_cost_reporting_amount', 20, 6);
            $table->timestamps();

            $table->index(['order_line_id', 'cost_layer_id']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->decimal('total_cogs_reporting_amount', 20, 6)->default(0);
            $table->char('currency_code', 3)->default('MXN');
            $table->timestamps();

            $table->index(['workspace_id', 'order_line_id']);
        });

        Schema::create('financial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('calculation_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('stage')->default('expected');
            $table->decimal('amount', 20, 6);
            $table->char('currency_code', 3);
            $table->decimal('reporting_amount', 20, 6)->nullable();
            $table->char('reporting_currency', 3)->nullable();
            $table->timestamp('occurred_at');
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'event_type', 'stage']);
            $table->index(['order_id', 'event_type']);
            $table->index(['workspace_id', 'occurred_at']);
        });

        Schema::create('profit_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calculation_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage')->default('expected');
            $table->decimal('revenue_amount', 20, 6)->default(0);
            $table->decimal('fees_amount', 20, 6)->default(0);
            $table->decimal('cogs_amount', 20, 6)->default(0);
            $table->decimal('profit_amount', 20, 6)->default(0);
            $table->char('currency_code', 3)->default('MXN');
            $table->boolean('is_incomplete')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'stage', 'calculation_version_id'], 'profit_snapshots_order_stage_version_unique');
            $table->index(['workspace_id', 'stage', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_snapshots');
        Schema::dropIfExists('financial_events');
        Schema::dropIfExists('cost_snapshots');
        Schema::dropIfExists('cost_layer_consumptions');
        Schema::dropIfExists('cost_layers');
        Schema::dropIfExists('calculation_versions');
    }
};
