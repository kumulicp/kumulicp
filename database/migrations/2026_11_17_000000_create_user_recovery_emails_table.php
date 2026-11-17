<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The recovery email of an account, kept here whichever account manager is in use:
     * a user in the directory has no row of its own in the users table until it first
     * logs in, so this is keyed by username (which is unique across the whole server).
     */
    public function up(): void
    {
        Schema::create('user_recovery_emails', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100)->unique();
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_emails');
    }
};
