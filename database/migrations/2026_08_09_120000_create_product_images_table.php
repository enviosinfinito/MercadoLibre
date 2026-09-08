<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->foreignId('channel_listing_id')->nullable()->constrained('channel_listings')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('publish_status', 32)->default('pending'); // pending|synced|failed
            $table->string('external_picture_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'publish_status']);
            $table->index(['workspace_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
