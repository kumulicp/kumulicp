<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_charts', function (Blueprint $table) {
            // What deploys the chart, as for an application: profile, generic or helmforge
            $table->string('source', 20)->default('generic');
            // What a catalog import found out about the chart (see ChartCatalogSync). Only kept for
            // a chart that is not the one its app is imported from: that one's is the app's own.
            $table->json('catalog')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_charts', function (Blueprint $table) {
            $table->dropColumn(['source', 'catalog']);
        });
    }
};
