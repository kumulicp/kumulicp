<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Exception messages (helm/kubernetes output especially) regularly exceed
    // 255 characters, which made saving a failed task fail itself.
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('error_message')->nullable()->change();
        });
    }
};
