<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_server_id');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('tool');
            $table->string('status')->default('pending');
            $table->string('triggered_by')->default('manual');
            $table->json('summary')->nullable();
            $table->longText('raw_output')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('org_server_id');
            $table->index('task_id');
            $table->index('tool');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_scans');
    }
};
