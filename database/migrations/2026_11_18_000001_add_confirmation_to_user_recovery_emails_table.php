<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recovery email is only trusted once the person has confirmed it from that inbox, and
     * a new one that replaces a working one only takes over after a day (the old one is kept
     * until then), so a stolen session can't quietly redirect password resets.
     */
    public function up(): void
    {
        Schema::table('user_recovery_emails', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->timestamp('pending_requested_at')->nullable();
            $table->string('previous_email')->nullable();
            $table->timestamp('active_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_recovery_emails', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'pending_email', 'pending_requested_at', 'previous_email', 'active_from']);
        });
    }
};
