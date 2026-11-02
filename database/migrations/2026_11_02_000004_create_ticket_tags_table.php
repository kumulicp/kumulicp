<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('ticket_ticket_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('ticket_tag_id');

            $table->primary(['ticket_id', 'ticket_tag_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_ticket_tag');
        Schema::dropIfExists('ticket_tags');
    }
};
