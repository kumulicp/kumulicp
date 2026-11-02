<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Append-only activity timeline
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type');
            $table->json('context')->nullable();
            // Staff-only events (e.g. tag changes) are never shown to customers
            $table->boolean('internal')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_events');
    }
};
