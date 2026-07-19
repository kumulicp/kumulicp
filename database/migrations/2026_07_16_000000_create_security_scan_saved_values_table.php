<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('security_scan_saved_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('type')->default('domain');
            $table->string('value');
            $table->timestamps();
            $table->index('organization_id');
            $table->unique(['organization_id', 'type', 'value']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_scan_saved_values');
    }
};
