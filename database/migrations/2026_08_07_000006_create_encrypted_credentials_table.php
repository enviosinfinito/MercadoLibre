<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encrypted_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encrypted_credentials');
    }
};
