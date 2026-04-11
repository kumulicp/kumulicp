<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecurringBackupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recurring_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('recurrence')->default('daily');
            $table->string('type')->default('default');
            $table->string('time');
            $table->integer('delete_after')->default(5);
            $table->string('delete_interval')->default('backups');
            $table->string('status')->default('inactive');
            $table->datetime('last_scheduled_at')->nullable();
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
        Schema::dropIfExists('recurring_backups');
    }
}
