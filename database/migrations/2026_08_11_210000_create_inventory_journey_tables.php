<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_name');
            $table->string('status')->default('ordered')->index();
            $table->timestamp('ordered_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('supplier_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_ordered', 20, 6);
            $table->decimal('qty_received', 20, 6)->default(0);
            $table->decimal('unit_cost_amount', 20, 6);
            $table->char('currency', 3)->default('MXN');
            $table->timestamps();

            $table->index(['workspace_id', 'variant_id']);
        });

        Schema::create('marketplace_inbounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('qty_sent', 20, 6);
            $table->decimal('qty_confirmed', 20, 6)->nullable();
            $table->string('external_inbound_id')->nullable()->index();
            $table->foreignId('full_stock_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'variant_id', 'status']);
            $table->index(['workspace_id', 'connection_id', 'status']);
        });

        Schema::table('cost_layers', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('source_type')
                ->constrained('supplier_purchase_order_lines')
                ->nullOnDelete();
            $table->decimal('qty_expected', 20, 6)->nullable()->after('qty_remaining');
        });

        Schema::table('full_stock_operations', function (Blueprint $table) {
            $table->foreignId('marketplace_inbound_id')
                ->nullable()
                ->after('variant_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('full_stock_operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketplace_inbound_id');
        });

        Schema::table('cost_layers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropColumn('qty_expected');
        });

        Schema::dropIfExists('marketplace_inbounds');
        Schema::dropIfExists('supplier_purchase_order_lines');
        Schema::dropIfExists('supplier_purchase_orders');
    }
};
