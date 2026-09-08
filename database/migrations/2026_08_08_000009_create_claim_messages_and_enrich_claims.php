<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('reason_detail')->nullable()->after('reason_id');
            $table->string('affects_reputation')->nullable()->after('reason_detail');
        });

        Schema::create('claim_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->string('external_message_key');
            $table->string('sender_role')->nullable();
            $table->string('receiver_role')->nullable();
            $table->string('stage')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['claim_id', 'external_message_key'], 'claim_messages_claim_external_unique');
            $table->index(['workspace_id', 'claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_messages');

        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['reason_detail', 'affects_reputation']);
        });
    }
};
