<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_states', function (Blueprint $table) {
            // Set when an admin took what was out of date as up to date without running anything.
            // Cleared when a step brings the aspect up to date.
            $table->timestamp('dismissed_at')->nullable()->after('drift_detected_at');
            $table->unsignedBigInteger('dismissed_by_id')->nullable()->after('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('update_states', function (Blueprint $table) {
            $table->dropColumn(['dismissed_at', 'dismissed_by_id']);
        });
    }
};
