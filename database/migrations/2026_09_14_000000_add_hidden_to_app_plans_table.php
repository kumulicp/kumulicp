<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_plans', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('archive');
        });
    }

    public function down(): void
    {
        Schema::table('app_plans', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
