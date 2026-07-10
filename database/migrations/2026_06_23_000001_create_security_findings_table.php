<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('security_scan_id');
            $table->string('severity');
            $table->string('title');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->text('remediation')->nullable();
            $table->string('rule_id')->nullable();
            $table->timestamps();
            $table->index('security_scan_id');
            $table->index('severity');
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_findings');
    }
};
