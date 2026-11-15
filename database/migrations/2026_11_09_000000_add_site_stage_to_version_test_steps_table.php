<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_test_steps', function (Blueprint $table) {
            // A shared app is tested in two stages: 'core' (the shared app itself) and then
            // 'site' (a site added to it). Null for steps that are tried out in one go.
            $table->string('stage')->nullable();
            // The site added in the 'site' stage, and the throwaway plan it was added with
            $table->unsignedBigInteger('site_app_instance_id')->nullable();
            $table->unsignedBigInteger('site_plan_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('version_test_steps', function (Blueprint $table) {
            $table->dropColumn(['stage', 'site_app_instance_id', 'site_plan_id']);
        });
    }
};
