<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A chart is offered as soon as it is added, and is in use once a plan picks it
    public function up(): void
    {
        Schema::table('app_charts', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('app_charts', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });
    }
};
