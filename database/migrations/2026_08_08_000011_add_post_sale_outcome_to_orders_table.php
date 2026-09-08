<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('post_sale_outcome', 40)->nullable()->after('status');
            $table->index(['workspace_id', 'post_sale_outcome']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'post_sale_outcome']);
            $table->dropColumn('post_sale_outcome');
        });
    }
};
