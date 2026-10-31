<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who triggered a task. Only filled in while audit logging is enabled.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('initiated_by_type', 32)->nullable();
            $table->unsignedBigInteger('initiated_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['initiated_by_type', 'initiated_by_id']);
        });
    }
};
