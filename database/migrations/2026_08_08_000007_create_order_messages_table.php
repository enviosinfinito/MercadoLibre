<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('packs')->nullOnDelete();
            $table->string('external_message_id');
            $table->string('external_pack_id')->nullable();
            $table->string('direction')->default('inbound'); // inbound = buyer→seller, outbound = seller→buyer
            $table->string('from_external_user_id')->nullable();
            $table->string('to_external_user_id')->nullable();
            $table->text('text')->nullable();
            $table->string('status')->default('available');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_message_id'], 'order_messages_connection_external_unique');
            $table->index(['order_id', 'sent_at']);
            $table->index(['workspace_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_messages');
    }
};
