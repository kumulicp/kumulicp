<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('default_support_assignee_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_categories');
    }
};
