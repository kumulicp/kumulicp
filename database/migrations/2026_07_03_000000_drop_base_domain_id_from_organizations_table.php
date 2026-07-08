<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['base_domain_id']);
            $table->dropColumn('base_domain_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('base_domain_id')->nullable()->default(null);
            $table->index('base_domain_id');
        });
    }
};
