<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secret_store_id')->constrained('secret_stores')->cascadeOnDelete();
            $table->string('path');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['secret_store_id', 'path', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
