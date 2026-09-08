<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('outbound_dry_run')->default(true)->after('default_costing_method');
        });

        Schema::table('variants', function (Blueprint $table) {
            $table->decimal('base_price_amount', 16, 6)->nullable()->after('status');
            $table->string('base_price_currency', 3)->nullable()->after('base_price_amount');
        });

        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->decimal('price_amount', 16, 6)->nullable()->after('status');
            $table->string('currency_code', 3)->nullable()->after('price_amount');
            $table->decimal('markup_pct', 8, 4)->nullable()->after('currency_code');
            $table->unsignedInteger('available_quantity')->nullable()->after('markup_pct');
            $table->timestamp('price_synced_at')->nullable()->after('available_quantity');
            $table->timestamp('stock_synced_at')->nullable()->after('price_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->dropColumn([
                'price_amount',
                'currency_code',
                'markup_pct',
                'available_quantity',
                'price_synced_at',
                'stock_synced_at',
            ]);
        });

        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn(['base_price_amount', 'base_price_currency']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('outbound_dry_run');
        });
    }
};
