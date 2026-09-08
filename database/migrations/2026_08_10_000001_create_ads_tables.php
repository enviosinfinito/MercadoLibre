<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_advertisers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_advertiser_id');
            $table->string('site_id', 8)->nullable();
            $table->string('name')->nullable();
            $table->string('account_name')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['connection_id', 'external_advertiser_id'],
                'ad_advertisers_connection_external_unique',
            );
            $table->index(['workspace_id', 'connection_id']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_advertiser_id')->constrained('ad_advertisers')->cascadeOnDelete();
            $table->string('external_campaign_id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('strategy')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['connection_id', 'external_campaign_id'],
                'ad_campaigns_connection_external_unique',
            );
            $table->index(['workspace_id', 'ad_advertiser_id']);
        });

        Schema::create('ad_spend_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_advertiser_id')->nullable()->constrained('ad_advertisers')->nullOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->string('external_campaign_id')->default('');
            $table->string('ml_item_id');
            $table->date('date');
            $table->decimal('cost', 20, 6)->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('prints')->default(0);
            $table->decimal('cpc', 20, 6)->nullable();
            $table->decimal('ctr', 20, 8)->nullable();
            $table->decimal('direct_amount', 20, 6)->default(0);
            $table->decimal('indirect_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->decimal('direct_units_quantity', 20, 6)->default(0);
            $table->decimal('indirect_units_quantity', 20, 6)->default(0);
            $table->decimal('advertising_items_quantity', 20, 6)->default(0);
            $table->decimal('acos', 20, 8)->nullable();
            $table->decimal('roas', 20, 8)->nullable();
            $table->char('currency_code', 3)->default('MXN');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'connection_id', 'external_campaign_id', 'ml_item_id', 'date'],
                'ad_spend_daily_grain_unique',
            );
            $table->index(['workspace_id', 'date']);
            $table->index(['ml_item_id', 'date']);
            $table->index(['ad_campaign_id', 'date']);
            $table->index(['connection_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_daily');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_advertisers');
    }
};
