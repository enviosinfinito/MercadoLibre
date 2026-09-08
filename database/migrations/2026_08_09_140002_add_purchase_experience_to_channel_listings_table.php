<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->json('purchase_experience')->nullable()->after('attributes_meta');
            $table->timestamp('purchase_experience_synced_at')->nullable()->after('purchase_experience');
            $table->string('pe_color', 32)->nullable()->after('purchase_experience_synced_at');
            $table->unsignedTinyInteger('pe_value')->nullable()->after('pe_color');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_experience',
                'purchase_experience_synced_at',
                'pe_color',
                'pe_value',
            ]);
        });
    }
};
