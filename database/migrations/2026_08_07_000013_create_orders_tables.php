<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_pack_id');
            $table->string('status')->default('open');
            $table->timestamps();

            $table->unique(['connection_id', 'external_pack_id']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('packs')->nullOnDelete();
            $table->string('external_order_id');
            $table->string('status')->default('pending');
            $table->string('buyer_external_id')->nullable();
            $table->char('currency_code', 3)->default('MXN');
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('raw_snapshot_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_order_id']);
            $table->index(['workspace_id', 'status', 'ordered_at']);
            $table->index(['workspace_id', 'connection_id']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_listing_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_item_id')->nullable();
            $table->string('external_variation_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('unit_price_amount', 20, 6)->default(0);
            $table->char('currency_code', 3)->default('MXN');
            $table->decimal('line_total_amount', 20, 6)->default(0);
            $table->string('match_status')->default('unmatched');
            $table->timestamps();

            $table->index(['workspace_id', 'order_id']);
            $table->index(['workspace_id', 'variant_id']);
            $table->index(['order_id', 'sku']);
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('source')->default('system');
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'occurred_at']);
            $table->index(['workspace_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('packs');
    }
};
