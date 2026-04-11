<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->integer('task_group')->nullable();
            $table->string('action_slug')->nullable();
            $table->integer('job_id')->default(0);
            $table->string('action_group');
            $table->string('description')->nullable();
            $table->json('prerequisites')->nullable();
            $table->json('custom_instructions')->nullable();
            $table->json('custom_values')->nullable();
            $table->boolean('background')->default(0);
            $table->boolean('notified')->default(0);
            $table->integer('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->string('status')->default('pending');
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
        Schema::dropIfExists('tasks');
    }
}
