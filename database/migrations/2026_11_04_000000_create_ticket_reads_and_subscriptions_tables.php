<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // How far each person has read each ticket (drives the unread dots and badge)
        Schema::create('ticket_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('read_at')->nullable();

            $table->unique(['ticket_id', 'user_id']);
            $table->index('user_id');
        });

        // Per person, per ticket: watching it (CC) and/or muting it
        Schema::create('ticket_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            // customer = a colleague CC'd on the customer side, support = a staff watcher
            $table->string('side')->default('customer');
            $table->boolean('watching')->default(false);
            $table->boolean('muted')->default(false);
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_subscriptions');
        Schema::dropIfExists('ticket_reads');
    }
};
