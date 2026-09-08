<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->json('picture_ids')->nullable()->after('attribute_combinations');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->dropColumn('picture_ids');
        });
    }
};
