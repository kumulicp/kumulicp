<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_test_runs', function (Blueprint $table) {
            // Set when a run tests one specific plan (a shared app's own hidden plan)
            // rather than the version and all the plans customers can pick
            $table->unsignedBigInteger('app_plan_id')->nullable()->after('app_version_id');
            $table->index(['app_version_id', 'app_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('version_test_runs', function (Blueprint $table) {
            $table->dropIndex(['app_version_id', 'app_plan_id', 'status']);
            $table->dropColumn('app_plan_id');
        });
    }
};
