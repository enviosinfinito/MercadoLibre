<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->timestamp('external_updated_at')->nullable()->after('raw_snapshot_id');
            $table->string('content_checksum', 64)->nullable()->after('external_updated_at');
            $table->index(['connection_id', 'external_updated_at'], 'channel_listings_connection_ext_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropIndex('channel_listings_connection_ext_updated_index');
            $table->dropColumn(['external_updated_at', 'content_checksum']);
        });
    }
};
