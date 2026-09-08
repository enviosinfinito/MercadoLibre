<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('claim_id')->nullable()->after('order_id')->constrained('claims')->nullOnDelete();
            $table->string('outcome', 40)->nullable()->after('status');
            $table->string('reason_id')->nullable()->after('reason');
            $table->string('reason_label')->nullable()->after('reason_id');
            $table->string('reason_group', 40)->nullable()->after('reason_label');
            $table->text('buyer_comment')->nullable()->after('reason_group');
            $table->string('inferred_reason_group', 40)->nullable()->after('buyer_comment');
            $table->string('inferred_reason_label')->nullable()->after('inferred_reason_group');
            $table->text('analysis_summary')->nullable()->after('inferred_reason_label');
            $table->string('analysis_confidence', 20)->nullable()->after('analysis_summary');
            $table->string('analysis_source', 40)->nullable()->after('analysis_confidence');
            $table->string('analysis_content_hash', 64)->nullable()->after('analysis_source');
            $table->timestamp('analyzed_at')->nullable()->after('analysis_content_hash');
            $table->timestamp('ordered_at')->nullable()->after('closed_at');
            $table->timestamp('delivered_at')->nullable()->after('ordered_at');
            $table->unsignedInteger('days_to_return')->nullable()->after('delivered_at');
            $table->decimal('returned_amount', 20, 6)->default(0)->after('days_to_return');
            $table->char('currency_code', 3)->default('MXN')->after('returned_amount');
            $table->decimal('estimated_loss', 20, 6)->nullable()->after('currency_code');
            $table->foreignId('dominant_product_id')->nullable()->after('estimated_loss')->constrained('products')->nullOnDelete();
            $table->string('dominant_ml_item_id')->nullable()->after('dominant_product_id');
            $table->json('meta')->nullable()->after('dominant_ml_item_id');

            $table->unique(['claim_id']);
            $table->index(['workspace_id', 'opened_at']);
            $table->index(['workspace_id', 'outcome']);
            $table->index(['workspace_id', 'dominant_product_id']);
            $table->index(['workspace_id', 'dominant_ml_item_id']);
        });

        Schema::create('return_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('ml_reason_id')->nullable()->index();
            $table->string('label');
            $table->string('group', 40)->index();
            $table->timestamps();
        });

        Schema::create('return_case_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->foreignId('channel_listing_id')->nullable()->constrained('channel_listings')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->string('ml_variation_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->string('variant_label')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('line_amount', 20, 6)->default(0);
            $table->string('reason_id')->nullable();
            $table->string('reason_label')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'product_id']);
            $table->index(['workspace_id', 'variant_id']);
            $table->index(['workspace_id', 'ml_item_id']);
            $table->index(['workspace_id', 'sku']);
            $table->index(['return_id']);
        });

        Schema::create('return_product_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->string('sku')->nullable();
            $table->date('date');
            $table->unsignedInteger('units_sold')->default(0);
            $table->decimal('gross_sales', 20, 6)->default(0);
            $table->unsignedInteger('returned_units')->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->decimal('returned_amount', 20, 6)->default(0);
            $table->decimal('return_rate', 8, 4)->default(0);
            $table->decimal('estimated_loss', 20, 6)->default(0);
            $table->string('dominant_reason_group', 40)->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->string('confidence_score', 20)->nullable();
            $table->json('sparkline_14d')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'date', 'product_id', 'ml_item_id'], 'return_product_daily_unique');
            $table->index(['workspace_id', 'date']);
            $table->index(['workspace_id', 'risk_score']);
            $table->index(['workspace_id', 'return_rate']);
        });

        Schema::create('return_variant_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->string('ml_variation_id')->nullable();
            $table->string('variant_label')->nullable();
            $table->string('sku')->nullable();
            $table->date('date');
            $table->unsignedInteger('units_sold')->default(0);
            $table->unsignedInteger('returned_units')->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->decimal('returned_amount', 20, 6)->default(0);
            $table->decimal('return_rate', 8, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'date', 'variant_id', 'ml_variation_id', 'ml_item_id'],
                'return_variant_daily_unique'
            );
            $table->index(['workspace_id', 'date', 'product_id']);
        });

        Schema::create('return_category_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->date('date');
            $table->unsignedInteger('units_sold')->default(0);
            $table->unsignedInteger('returned_units')->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->decimal('returned_amount', 20, 6)->default(0);
            $table->decimal('return_rate', 8, 4)->default(0);
            $table->unsignedInteger('products_affected')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'date', 'category_id'], 'return_category_daily_unique');
            $table->index(['workspace_id', 'date']);
        });

        Schema::create('return_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('alert_type', 60);
            $table->string('level', 20);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('open');
            $table->json('context')->nullable();
            $table->string('dedupe_key', 64);
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'dedupe_key']);
            $table->index(['workspace_id', 'status', 'level']);
            $table->index(['workspace_id', 'product_id']);
        });

        Schema::create('return_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('insight_type', 60);
            $table->string('severity', 20)->default('info');
            $table->string('title');
            $table->text('body');
            $table->json('context')->nullable();
            $table->string('period_key', 40)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'period_key', 'severity']);
        });

        Schema::create('return_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('review');
            $table->text('notes')->nullable();
            $table->text('action_taken')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'product_id', 'status']);
            $table->index(['workspace_id', 'ml_item_id']);
        });

        Schema::create('return_action_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_action_id')->constrained('return_actions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('event_type', 40)->default('note');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['return_action_id', 'created_at']);
        });

        Schema::create('return_financial_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->decimal('sale_amount', 20, 6)->default(0);
            $table->decimal('refund_amount', 20, 6)->default(0);
            $table->decimal('shipping_cost', 20, 6)->default(0);
            $table->decimal('return_shipping_cost', 20, 6)->default(0);
            $table->decimal('product_cost', 20, 6)->default(0);
            $table->decimal('fee_not_recovered', 20, 6)->default(0);
            $table->decimal('other_costs', 20, 6)->default(0);
            $table->decimal('estimated_total_loss', 20, 6)->default(0);
            $table->char('currency_code', 3)->default('MXN');
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['return_id']);
            $table->index(['workspace_id']);
        });

        Schema::create('return_message_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->string('corpus_hash', 64);
            $table->string('fingerprint', 64)->nullable();
            $table->string('source', 40);
            $table->string('reason_group', 40)->nullable();
            $table->string('reason_label')->nullable();
            $table->text('summary')->nullable();
            $table->json('evidence')->nullable();
            $table->json('signals')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('confidence', 20)->nullable();
            $table->timestamps();

            $table->unique(['return_id']);
            $table->index(['workspace_id', 'fingerprint']);
            $table->index(['workspace_id', 'reason_group']);
        });

        Schema::create('return_product_narratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->string('period_key', 40);
            $table->text('summary')->nullable();
            $table->json('reason_breakdown')->nullable();
            $table->json('top_phrases')->nullable();
            $table->string('source', 40)->default('rules');
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('cases_analyzed')->default(0);
            $table->unsignedInteger('ai_calls_used')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'product_id', 'ml_item_id', 'period_key'], 'return_product_narrative_unique');
            $table->index(['workspace_id', 'period_key']);
        });

        Schema::create('return_comment_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ml_item_id')->nullable();
            $table->string('cluster_key', 64);
            $table->string('label')->nullable();
            $table->text('representative_phrase')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->string('reason_group', 40)->nullable();
            $table->json('sample_return_ids')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'product_id', 'ml_item_id', 'cluster_key'], 'return_comment_cluster_unique');
        });

        Schema::table('channel_listings', function (Blueprint $table) {
            $table->string('category_id')->nullable()->after('status');
            $table->string('category_name')->nullable()->after('category_id');
            $table->index(['workspace_id', 'category_id']);
        });

        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->json('attribute_combinations')->nullable()->after('sku_external');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listing_variants', function (Blueprint $table) {
            $table->dropColumn('attribute_combinations');
        });

        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'category_id']);
            $table->dropColumn(['category_id', 'category_name']);
        });

        Schema::dropIfExists('return_comment_clusters');
        Schema::dropIfExists('return_product_narratives');
        Schema::dropIfExists('return_message_analyses');
        Schema::dropIfExists('return_financial_impacts');
        Schema::dropIfExists('return_action_notes');
        Schema::dropIfExists('return_actions');
        Schema::dropIfExists('return_insights');
        Schema::dropIfExists('return_alerts');
        Schema::dropIfExists('return_category_daily_stats');
        Schema::dropIfExists('return_variant_daily_stats');
        Schema::dropIfExists('return_product_daily_stats');
        Schema::dropIfExists('return_case_items');
        Schema::dropIfExists('return_reasons');

        Schema::table('returns', function (Blueprint $table) {
            $table->dropUnique(['claim_id']);
            $table->dropIndex(['workspace_id', 'opened_at']);
            $table->dropIndex(['workspace_id', 'outcome']);
            $table->dropIndex(['workspace_id', 'dominant_product_id']);
            $table->dropIndex(['workspace_id', 'dominant_ml_item_id']);
            $table->dropConstrainedForeignId('claim_id');
            $table->dropConstrainedForeignId('dominant_product_id');
            $table->dropColumn([
                'outcome',
                'reason_id',
                'reason_label',
                'reason_group',
                'buyer_comment',
                'inferred_reason_group',
                'inferred_reason_label',
                'analysis_summary',
                'analysis_confidence',
                'analysis_source',
                'analysis_content_hash',
                'analyzed_at',
                'ordered_at',
                'delivered_at',
                'days_to_return',
                'returned_amount',
                'currency_code',
                'estimated_loss',
                'dominant_ml_item_id',
                'meta',
            ]);
        });
    }
};
