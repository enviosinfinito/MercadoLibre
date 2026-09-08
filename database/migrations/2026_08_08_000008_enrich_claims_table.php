<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('type')->nullable()->after('external_claim_id');
            $table->string('stage')->nullable()->after('type');
            $table->string('reason_id')->nullable()->after('reason');
            $table->string('resource')->nullable()->after('reason_id');
            $table->string('resource_external_id')->nullable()->after('resource');
            $table->foreignId('raw_snapshot_id')->nullable()->after('closed_at')
                ->constrained('raw_resource_snapshots')->nullOnDelete();
            $table->json('meta')->nullable()->after('raw_snapshot_id');

            $table->index(['workspace_id', 'order_id']);
            $table->unique(['connection_id', 'external_claim_id'], 'claims_connection_external_unique');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->dropIndex('claims_connection_id_external_claim_id_index');
        });

        DB::table('connection_sync_profiles')
            ->where('resource_key', 'claims')
            ->update(['enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->index(['connection_id', 'external_claim_id'], 'claims_connection_id_external_claim_id_index');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->dropUnique('claims_connection_external_unique');
            $table->dropIndex(['workspace_id', 'order_id']);
            $table->dropConstrainedForeignId('raw_snapshot_id');
            $table->dropColumn([
                'type',
                'stage',
                'reason_id',
                'resource',
                'resource_external_id',
                'meta',
            ]);
        });
    }
};
