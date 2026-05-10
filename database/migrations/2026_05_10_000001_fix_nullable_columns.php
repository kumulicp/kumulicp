<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('app_instance_id')->nullable()->default(null)->change();
            $table->boolean('default_email_server')->default(false)->change();
            $table->boolean('default_database_server')->default(false)->change();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_domain_id')->nullable()->default(null)->change();
            $table->unsignedBigInteger('base_domain_id')->nullable()->default(null)->change();
        });

        Schema::table('app_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('web_server_id')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
            $table->boolean('default_email_server')->nullable(false)->change();
            $table->boolean('default_database_server')->nullable(false)->change();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_domain_id')->nullable(false)->change();
            $table->unsignedBigInteger('base_domain_id')->nullable(false)->change();
        });

        Schema::table('app_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('web_server_id')->nullable(false)->change();
        });
    }
};
