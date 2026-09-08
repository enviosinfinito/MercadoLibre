<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->text('avatar_url')->nullable()->after('permalink');
            $table->string('reputation_level')->nullable()->after('avatar_url');
            $table->string('power_seller_status')->nullable()->after('reputation_level');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'reputation_level', 'power_seller_status']);
        });
    }
};
