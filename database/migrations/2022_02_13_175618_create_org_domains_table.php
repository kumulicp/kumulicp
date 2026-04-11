<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrgDomainsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('org_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->unsignedBigInteger('parent_domain_id')->nullable();
            $table->unsignedBigInteger('tld_id')->nullable();
            $table->integer('server_domain_id')->nullable();
            $table->integer('domain_id')->nullable();
            $table->integer('transfer_id')->nullable();
            $table->boolean('is_managed')->nullable();
            $table->string('host');
            $table->string('name')->unique();
            $table->string('type')->default('connection');
            $table->string('value')->nullable();
            $table->string('source')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('country_phone_code')->nullable();
            $table->string('email_address')->nullable();
            $table->string('price')->nullable();
            $table->decimal('icann_fee', 8, 2)->nullable();
            $table->boolean('is_premium')->default(0);
            $table->decimal('premium_registration_price')->nullable();
            $table->decimal('premium_renewal_price')->nullable();
            $table->decimal('premium_restore_price')->nullable();
            $table->decimal('premium_transfer_price')->nullable();
            $table->boolean('registered')->nullable();
            $table->decimal('charged_amount')->nullable();
            $table->boolean('whois_guard_enabled')->nullable();
            $table->integer('whois_guard_id')->nullable();
            $table->boolean('non_real_time_domain')->nullable();
            $table->integer('order_id')->nullable();
            $table->integer('transaction_id')->nullable();
            $table->boolean('is_auto_renew')->default(0);
            $table->boolean('is_primary')->default(0);
            $table->boolean('email_enabled')->nullable();
            $table->string('email_status')->default('disabled');
            $table->integer('email_server_id')->nullable();
            $table->integer('email_server_customer_id')->nullable();
            $table->integer('email_server_domain_id')->nullable();
            $table->string('dkim_public_key')->nullable();
            $table->string('ttl')->default();
            $table->json('settings')->nullable();
            $table->integer('status_id')->nullable();
            $table->string('status_description')->nullable();
            $table->string('status');
            $table->dateTime('registered_at')->nullable();
            $table->DateTime('transferred_at')->nullable();
            $table->dateTime('expires_at')->nullable();
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
        Schema::dropIfExists('org_domains');
    }
}
