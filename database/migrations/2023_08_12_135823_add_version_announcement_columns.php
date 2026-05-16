<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->string('announcement_location')->nullable();
            $table->unsignedBigInteger('announcement_id')->nullable();
            $table->string('announcement_url')->nullable();
            $table->index('announcement_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropIndex(['announcement_id']);
            $table->dropColumn(['announcement_location', 'announcement_id', 'announcement_url']);
        });
    }
};
