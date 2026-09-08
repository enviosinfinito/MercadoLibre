<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->json('reputation_meta')->nullable()->after('power_seller_status');
            $table->timestamp('reputation_synced_at')->nullable()->after('reputation_meta');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['reputation_meta', 'reputation_synced_at']);
        });
    }
};
