<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->unsignedBigInteger('primary_domain_id');
            $table->unsignedBigInteger('base_domain_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('primary_contact_id')->nullable();
            $table->unsignedBigInteger('account_test_id')->nullable();
            $table->string('slug')->unique();
            $table->string('api_token', 80)
                ->unique()
                ->nullable()
                ->default(null);
            $table->string('name');
            $table->string('description');
            $table->string('email');
            $table->string('phone_number');
            $table->string('domain_name')->nullable();
            $table->string('contact_first_name');
            $table->string('contact_last_name');
            $table->string('contact_email');
            $table->string('contact_phone_number');
            $table->string('street');
            $table->string('zipcode');
            $table->string('city');
            $table->string('state');
            $table->string('country');
            $table->string('discount_id')->nullable();
            $table->string('type')->default('business');
            $table->string('ldap_map')->nullable();
            $table->string('secretpw')->nullable();
            $table->json('settings');
            $table->string('status');
            $table->dateTime('deactivate_at')->nullable();
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
        Schema::dropIfExists('organizations');
    }
}
