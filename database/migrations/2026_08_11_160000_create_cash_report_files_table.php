<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('report_kind', 32);
            $table->string('remote_file_name');
            $table->string('storage_path');
            $table->string('report_shape', 16)->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamp('begin_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('report_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'connection_id', 'report_kind', 'created_at'], 'cash_report_files_conn_kind_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_report_files');
    }
};
