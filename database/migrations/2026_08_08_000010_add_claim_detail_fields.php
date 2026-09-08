<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->text('problem')->nullable()->after('reason_detail');
            $table->string('status_title')->nullable()->after('problem');
            $table->string('status_description')->nullable()->after('status_title');
            $table->timestamp('due_at')->nullable()->after('status_description');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['problem', 'status_title', 'status_description', 'due_at']);
        });
    }
};
