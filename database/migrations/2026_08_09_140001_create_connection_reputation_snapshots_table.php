<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_reputation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->date('capture_date');
            $table->timestamp('captured_at');
            $table->string('level_id')->nullable();
            $table->string('power_seller_status')->nullable();
            $table->string('worst_band')->nullable();
            $table->decimal('claims_rate', 8, 6)->nullable();
            $table->decimal('cancellations_rate', 8, 6)->nullable();
            $table->decimal('delayed_handling_rate', 8, 6)->nullable();
            $table->unsignedInteger('sales_completed')->nullable();
            $table->boolean('is_milestone')->default(false);
            $table->string('milestone_kind')->nullable();
            $table->string('milestone_label')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'captured_at']);
            $table->index(['connection_id', 'is_milestone']);
            $table->unique(['connection_id', 'capture_date'], 'conn_rep_snap_conn_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_reputation_snapshots');
    }
};
