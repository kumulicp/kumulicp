<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrgSubDomainsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('org_subdomains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->unsignedBigInteger('parent_domain_id')->nullable();
            $table->string('host');
            $table->string('name');
            $table->string('type')->default('connection');
            $table->string('value', 500)->nullable();
            $table->integer('ttl')->nullable();
            $table->json('settings')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('org_subdomains');
    }
}
