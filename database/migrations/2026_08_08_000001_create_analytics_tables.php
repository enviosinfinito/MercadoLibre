<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility'); // platform_template|workspace|personal
            $table->string('slug')->nullable();
            $table->json('layout')->nullable();
            $table->json('global_filters')->nullable();
            $table->foreignId('cloned_from_id')->nullable()->constrained('analytics_dashboards')->nullOnDelete();
            $table->boolean('is_home')->default(false);
            $table->timestamps();

            $table->index(['visibility', 'workspace_id']);
            $table->index(['workspace_id', 'owner_user_id']);
        });

        Schema::create('analytics_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_dashboard_id')->constrained('analytics_dashboards')->cascadeOnDelete();
            $table->string('type'); // kpi|line|bar|pie|table|pivot
            $table->string('title');
            $table->json('query');
            $table->json('viz_options')->nullable();
            $table->json('grid')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['analytics_dashboard_id', 'sort_order']);
        });

        Schema::create('analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility')->default('personal'); // personal|workspace
            $table->string('viz_type')->default('table');
            $table->json('query');
            $table->json('viz_options')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'visibility']);
            $table->index(['workspace_id', 'owner_user_id']);
        });

        Schema::create('analytics_dashboard_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_dashboard_id')->constrained('analytics_dashboards')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role_name')->nullable();
            $table->string('permission')->default('view'); // view|edit
            $table->timestamps();

            $table->unique(['analytics_dashboard_id', 'user_id', 'role_name'], 'analytics_dashboard_shares_unique');
        });

        Schema::table('export_runs', function (Blueprint $table) {
            $table->foreignId('analytics_report_id')->nullable()->after('saved_report_view_id')->constrained()->nullOnDelete();
            $table->json('query')->nullable()->after('filters');
        });

        Schema::table('scheduled_exports', function (Blueprint $table) {
            $table->foreignId('analytics_report_id')->nullable()->after('saved_report_view_id')->constrained()->nullOnDelete();
            $table->json('query')->nullable()->after('delivery');
        });

        Schema::table('saved_report_views', function (Blueprint $table) {
            $table->foreignId('analytics_report_id')->nullable()->after('is_shared')->constrained()->nullOnDelete();
            $table->json('query')->nullable()->after('columns');
        });
    }

    public function down(): void
    {
        Schema::table('saved_report_views', function (Blueprint $table) {
            $table->dropConstrainedForeignId('analytics_report_id');
            $table->dropColumn('query');
        });

        Schema::table('scheduled_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('analytics_report_id');
            $table->dropColumn('query');
        });

        Schema::table('export_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('analytics_report_id');
            $table->dropColumn('query');
        });

        Schema::dropIfExists('analytics_dashboard_shares');
        Schema::dropIfExists('analytics_reports');
        Schema::dropIfExists('analytics_widgets');
        Schema::dropIfExists('analytics_dashboards');
    }
};
