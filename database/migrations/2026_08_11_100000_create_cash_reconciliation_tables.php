<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_payment_id');
            $table->string('status')->nullable();
            $table->string('status_detail')->nullable();
            $table->decimal('transaction_amount', 20, 6)->nullable();
            $table->decimal('marketplace_fee_amount', 20, 6)->nullable();
            $table->decimal('shipping_cost_amount', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('net_received_amount', 20, 6)->nullable();
            $table->char('currency_code', 3)->default('MXN');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('money_release_at')->nullable();
            $table->boolean('is_released')->nullable();
            $table->string('reconciliation_status')->default('pending');
            $table->decimal('expected_net_amount', 20, 6)->nullable();
            $table->decimal('diff_amount', 20, 6)->nullable();
            $table->foreignId('raw_snapshot_id')->nullable()->constrained('raw_resource_snapshots')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_payment_id'], 'mpayments_connection_external_unique');
            $table->index(['workspace_id', 'reconciliation_status']);
            $table->index(['workspace_id', 'order_id']);
            $table->index(['workspace_id', 'paid_at']);
        });

        Schema::create('cash_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type');
            $table->string('transaction_type')->nullable();
            $table->string('external_source_id')->nullable();
            $table->string('external_order_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('external_shipping_id')->nullable();
            $table->decimal('gross_amount', 20, 6)->nullable();
            $table->decimal('fee_amount', 20, 6)->nullable();
            $table->decimal('shipping_fee_amount', 20, 6)->nullable();
            $table->decimal('tax_amount', 20, 6)->nullable();
            $table->decimal('financing_fee_amount', 20, 6)->nullable();
            $table->decimal('net_amount', 20, 6);
            $table->char('currency_code', 3)->default('MXN');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->boolean('is_released')->nullable();
            $table->string('provenance');
            $table->string('idempotency_key');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'idempotency_key'], 'cash_ledger_connection_idem_unique');
            $table->index(['workspace_id', 'entry_type', 'occurred_at']);
            $table->index(['workspace_id', 'external_source_id']);
            $table->index(['workspace_id', 'external_order_id']);
            $table->index(['workspace_id', 'released_at']);
        });

        Schema::create('cash_reconciliation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_ledger_entry_id')->constrained('cash_ledger_entries')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketplace_payment_id')->nullable()->constrained('marketplace_payments')->nullOnDelete();
            $table->decimal('allocated_amount', 20, 6);
            $table->decimal('expected_amount', 20, 6)->nullable();
            $table->decimal('diff_amount', 20, 6)->nullable();
            $table->string('match_method');
            $table->string('status')->default('matched');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['cash_ledger_entry_id', 'order_id', 'marketplace_payment_id'], 'cash_recon_link_unique');
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'order_id']);
        });

        Schema::create('cash_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->decimal('expected_net_total', 20, 6)->default(0);
            $table->decimal('settled_net_total', 20, 6)->default(0);
            $table->decimal('released_net_total', 20, 6)->default(0);
            $table->decimal('withdrawn_net_total', 20, 6)->default(0);
            $table->decimal('diff_amount', 20, 6)->default(0);
            $table->unsignedInteger('orders_matched')->default(0);
            $table->unsignedInteger('orders_unmatched')->default(0);
            $table->unsignedInteger('entries_unmatched')->default(0);
            $table->string('status')->default('incomplete');
            $table->json('payload')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliation_runs');
        Schema::dropIfExists('cash_reconciliation_links');
        Schema::dropIfExists('cash_ledger_entries');
        Schema::dropIfExists('marketplace_payments');
    }
};
