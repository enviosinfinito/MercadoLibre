<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('external_item_id');
            $table->string('title')->nullable();
            $table->string('status')->default('active');
            $table->string('permalink')->nullable();
            $table->unsignedBigInteger('raw_snapshot_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'provider', 'external_item_id'],
                'channel_listings_workspace_provider_item_unique'
            );
            $table->index(['connection_id', 'external_item_id']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('channel_listing_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_variation_id')->nullable();
            $table->string('sku_external')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(
                ['channel_listing_id', 'external_variation_id'],
                'channel_listing_variants_listing_variation_unique'
            );
            $table->index(['workspace_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_listing_variants');
        Schema::dropIfExists('channel_listings');
    }
};
