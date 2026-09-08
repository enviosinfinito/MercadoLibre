<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->string('logistic_type')->nullable()->after('status');
            $table->string('inventory_id')->nullable()->after('logistic_type');
            $table->json('shipping_meta')->nullable()->after('inventory_id');
        });

        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->string('user_product_id')->nullable()->after('external_variation_id');
            $table->string('inventory_id')->nullable()->after('user_product_id');
            $table->timestamp('channel_stock_synced_at')->nullable()->after('stock_synced_at');
        });

        Schema::create('channel_stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_listing_variant_id')->constrained()->cascadeOnDelete();
            $table->string('location_type'); // selling_address | meli_facility | seller_warehouse
            $table->string('store_id')->default('');
            $table->string('network_node_id')->default('');
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('not_available_quantity', 20, 6)->nullable();
            $table->string('stock_version')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['channel_listing_variant_id', 'location_type', 'store_id', 'network_node_id'],
                'channel_stock_locations_unique'
            );
            $table->index(['workspace_id', 'location_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_stock_locations');

        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->dropColumn(['user_product_id', 'inventory_id', 'channel_stock_synced_at']);
        });

        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropColumn(['logistic_type', 'inventory_id', 'shipping_meta']);
        });
    }
};
