<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_commands', function (Blueprint $table) {
            $table->boolean('dry_run')->default(true)->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_commands', function (Blueprint $table) {
            $table->dropColumn('dry_run');
        });
    }
};
