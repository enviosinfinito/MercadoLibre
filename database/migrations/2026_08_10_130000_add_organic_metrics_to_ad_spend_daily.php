<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_spend_daily', function (Blueprint $table) {
            $table->decimal('organic_units_quantity', 20, 6)->default(0)->after('advertising_items_quantity');
            $table->decimal('organic_units_amount', 20, 6)->default(0)->after('organic_units_quantity');
            $table->decimal('organic_items_quantity', 20, 6)->default(0)->after('organic_units_amount');
            $table->decimal('units_quantity', 20, 6)->default(0)->after('organic_items_quantity');
            $table->decimal('direct_items_quantity', 20, 6)->default(0)->after('units_quantity');
            $table->decimal('indirect_items_quantity', 20, 6)->default(0)->after('direct_items_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('ad_spend_daily', function (Blueprint $table) {
            $table->dropColumn([
                'organic_units_quantity',
                'organic_units_amount',
                'organic_items_quantity',
                'units_quantity',
                'direct_items_quantity',
                'indirect_items_quantity',
            ]);
        });
    }
};
