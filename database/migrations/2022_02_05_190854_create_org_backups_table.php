<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrgBackupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('org_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('scheduled_backup_id')->nullable();
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->unsignedBigInteger('org_server_id')->nullable();
            $table->string('job_id')->nullable();
            $table->string('backup_name')->nullable();
            $table->string('action');
            $table->string('source')->nullable();
            $table->string('type', 50);
            $table->json('settings')->nullable();
            $table->string('status', 20);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('delete_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
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
        Schema::dropIfExists('org_backups');
    }
}
