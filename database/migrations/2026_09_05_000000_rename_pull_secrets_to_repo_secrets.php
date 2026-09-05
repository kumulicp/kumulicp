<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('pull_secrets', 'repo_secrets');

        Schema::table('repo_secrets', function (Blueprint $table) {
            $table->string('type')->default('image')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('repo_secrets', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('repo_secrets', 'pull_secrets');
    }
};
