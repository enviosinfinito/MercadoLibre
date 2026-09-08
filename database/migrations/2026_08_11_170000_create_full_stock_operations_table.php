<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('full_stock_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_operation_id');
            $table->string('seller_id')->nullable();
            $table->string('inventory_id')->nullable()->index();
            $table->string('seller_product_id')->nullable();
            $table->string('operation_type')->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->decimal('available_quantity_delta', 20, 6)->nullable();
            $table->decimal('not_available_quantity_delta', 20, 6)->nullable();
            $table->decimal('result_total', 20, 6)->nullable();
            $table->decimal('result_available', 20, 6)->nullable();
            $table->decimal('result_not_available', 20, 6)->nullable();
            $table->json('not_available_detail')->nullable();
            $table->json('external_references')->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('channel_listing_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['connection_id', 'external_operation_id'],
                'full_stock_operations_connection_external_unique'
            );
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['workspace_id', 'operation_type']);
            $table->index(['workspace_id', 'inventory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('full_stock_operations');
    }
};
