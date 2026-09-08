<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'code']);
            $table->index(['workspace_id', 'is_default']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'variant_id', 'warehouse_id'], 'inventory_items_workspace_variant_warehouse_unique');
        });

        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type');
            $table->decimal('quantity_delta', 20, 6);
            $table->decimal('quantity_after', 20, 6)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'inventory_ledger_workspace_idempotency_unique');
            $table->index(['inventory_item_id', 'occurred_at']);
            $table->index(['workspace_id', 'movement_type', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 20, 6)->default(0);
            $table->decimal('quantity_reserved', 20, 6)->default(0);
            $table->decimal('quantity_available', 20, 6)->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'quantity_available']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('order_line_id')->nullable()->index();
            $table->decimal('quantity', 20, 6);
            $table->string('status')->default('active');
            $table->string('idempotency_key');
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'reservations_workspace_idempotency_unique');
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_ledger');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');
    }
};
