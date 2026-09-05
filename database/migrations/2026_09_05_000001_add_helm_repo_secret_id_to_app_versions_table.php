<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('helm_repo_secret_id')->nullable()->after('pull_secret_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropColumn('helm_repo_secret_id');
        });
    }
};
