<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->json('account_profile')->nullable()->after('reputation_synced_at');
            $table->timestamp('account_profile_synced_at')->nullable()->after('account_profile');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['account_profile', 'account_profile_synced_at']);
        });
    }
};
