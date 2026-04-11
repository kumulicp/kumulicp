<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('app_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('web_server_id')->nullable();
            $table->unsignedBigInteger('database_server_id')->nullable();
            $table->unsignedBigInteger('sso_server_id')->nullable();
            $table->unsignedBigInteger('shared_app_id')->nullable();
            $table->integer('display_order')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('features')->nullable();
            $table->string('payment_enabled')->default(0);
            $table->boolean('domain_enabled')->default(0);
            $table->boolean('email_enabled')->default(0);
            $table->integer('domain_max')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_default')->default(0);
            $table->boolean('archive')->default(0);
            $table->timestamp('release_date')->nullable();
            $table->timestamp('suppress_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('app_plans');
    }
}
