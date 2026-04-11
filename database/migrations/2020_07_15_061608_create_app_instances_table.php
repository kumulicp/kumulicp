<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppInstancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('app_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('primary_domain_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('web_server_id');
            $table->unsignedBigInteger('database_server_id')->nullable();
            $table->unsignedBigInteger('sso_server_id')->nullable();
            $table->integer('server_domain_id')->nullable();
            $table->integer('server_database_id')->nullable();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('link')->nullable();
            $table->string('databasename')->nullable();
            $table->string('api_password')->nullable();
            $table->decimal('install_time')->nullable();
            $table->json('settings')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('deactivate_at')->nullable();
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
        Schema::dropIfExists('app_instances');
    }
}
