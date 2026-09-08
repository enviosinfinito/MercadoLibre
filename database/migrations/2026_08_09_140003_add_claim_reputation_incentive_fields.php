<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->boolean('has_incentive')->nullable()->after('affects_reputation');
            $table->timestamp('reputation_due_at')->nullable()->after('has_incentive');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['has_incentive', 'reputation_due_at']);
        });
    }
};
