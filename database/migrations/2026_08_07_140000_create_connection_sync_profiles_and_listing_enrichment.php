<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_sync_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_key');
            $table->boolean('enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'resource_key'], 'connection_sync_profiles_connection_resource_unique');
            $table->index(['workspace_id', 'resource_key']);
        });

        Schema::table('channel_listings', function (Blueprint $table) {
            $table->text('description')->nullable()->after('permalink');
            $table->json('pictures')->nullable()->after('description');
            $table->json('attributes_meta')->nullable()->after('pictures');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropColumn(['description', 'pictures', 'attributes_meta']);
        });

        Schema::dropIfExists('connection_sync_profiles');
    }
};
