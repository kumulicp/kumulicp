<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->foreignId('pull_secret_id')->nullable()->after('settings')->constrained('pull_secrets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pull_secret_id');
        });
    }
};
