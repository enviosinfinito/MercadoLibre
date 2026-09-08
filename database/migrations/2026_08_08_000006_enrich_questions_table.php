<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('buyer_external_id')->nullable()->after('external_item_id');
            $table->foreignId('raw_snapshot_id')->nullable()->after('answered_at')
                ->constrained('raw_resource_snapshots')->nullOnDelete();
            $table->json('meta')->nullable()->after('raw_snapshot_id');

            $table->unique(['connection_id', 'external_question_id'], 'questions_connection_external_unique');
            $table->index(['workspace_id', 'external_item_id']);
            $table->index(['connection_id', 'buyer_external_id']);
        });

        // Drop the old non-unique composite only after the unique exists so the
        // connection_id FK still has a usable leftmost-prefix index.
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('questions_connection_id_external_question_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->index(['connection_id', 'external_question_id'], 'questions_connection_id_external_question_id_index');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique('questions_connection_external_unique');
            $table->dropIndex(['workspace_id', 'external_item_id']);
            $table->dropIndex(['connection_id', 'buyer_external_id']);
            $table->dropConstrainedForeignId('raw_snapshot_id');
            $table->dropColumn(['buyer_external_id', 'meta']);
        });
    }
};
