<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_runs', function (Blueprint $table) {
            $table->uuid('token')->nullable()->unique()->after('id');
            $table->string('target_module')->nullable()->after('report_type');
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->string('selection_mode')->nullable()->after('progress');
            $table->unsignedBigInteger('total_rows')->nullable()->after('selection_mode');
            $table->string('estimated_time')->nullable()->after('total_rows');
            $table->json('export_params')->nullable()->after('query');
            $table->json('stats')->nullable()->after('export_params');
            $table->string('download_link')->nullable()->after('storage_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('download_link');
            $table->timestamp('file_expired_at')->nullable()->after('file_size');
            $table->timestamp('last_activity_at')->nullable()->after('finished_at');
            $table->foreignId('scheduled_export_id')->nullable()->after('analytics_report_id')
                ->constrained('scheduled_exports')->nullOnDelete();
            $table->index(['workspace_id', 'target_module', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('user_export_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_module');
            $table->string('name');
            $table->json('columns');
            $table->json('formula_columns')->nullable();
            $table->string('references_format')->nullable();
            $table->string('row_granularity')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'target_module']);
        });

        Schema::create('export_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('target_module');
            $table->json('columns');
            $table->json('formula_columns')->nullable();
            $table->string('references_format')->nullable();
            $table->string('row_granularity')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'target_module', 'is_active']);
        });

        Schema::table('scheduled_exports', function (Blueprint $table) {
            $table->string('target_module')->nullable()->after('report_type');
            $table->string('schedule_type')->nullable()->after('format');
            $table->json('schedule_config')->nullable()->after('schedule_type');
            $table->json('delivery_channels')->nullable()->after('delivery');
            $table->json('filters')->nullable()->after('query');
            $table->foreignId('user_export_preference_id')->nullable()->after('saved_report_view_id')
                ->constrained('user_export_preferences')->nullOnDelete();
            $table->foreignId('export_preset_id')->nullable()->after('user_export_preference_id')
                ->constrained('export_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('export_preset_id');
            $table->dropConstrainedForeignId('user_export_preference_id');
            $table->dropColumn([
                'target_module',
                'schedule_type',
                'schedule_config',
                'delivery_channels',
                'filters',
            ]);
        });

        Schema::dropIfExists('export_presets');
        Schema::dropIfExists('user_export_preferences');

        Schema::table('export_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_export_id');
            $table->dropIndex(['workspace_id', 'target_module', 'created_at']);
            $table->dropIndex(['user_id', 'status', 'created_at']);
            $table->dropColumn([
                'token',
                'target_module',
                'progress',
                'selection_mode',
                'total_rows',
                'estimated_time',
                'export_params',
                'stats',
                'download_link',
                'file_size',
                'file_expired_at',
                'last_activity_at',
            ]);
        });
    }
};
