<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_instance_id');
            $table->unsignedBigInteger('default_backup_server_id')->nullable();
            $table->string('name');
            $table->string('host');
            $table->string('address');
            $table->string('api_key');
            $table->string('api_secret');
            $table->string('ip');
            $table->string('type');
            $table->string('internal_address');
            $table->string('interface');
            $table->string('backup_driver')->nullable();
            $table->boolean('default_web_server');
            $table->boolean('default_email_server');
            $table->boolean('default_database_server');
            $table->boolean('is_backup_server')->default(false);
            $table->json('settings')->nullable();
            $table->string('status');
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
        Schema::dropIfExists('servers');
    }
}
