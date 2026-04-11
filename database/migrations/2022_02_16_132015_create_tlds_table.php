<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tlds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20);
            $table->string('default_driver', 100);
            $table->decimal('standard_price')->default(0.0);
            $table->boolean('privacy_allowed')->default(1);
            $table->boolean('registration_disabled')->default(0);
            $table->boolean('non_real_time')->nullable();
            $table->integer('min_register_years')->nullable();
            $table->integer('max_register_years')->nullable();
            $table->integer('min_renew_years')->nullable();
            $table->integer('max_renew_years')->nullable();
            $table->integer('min_transfer_years')->nullable();
            $table->integer('max_transfer_years')->nullable();
            $table->boolean('is_api_registerable')->default(false);
            $table->boolean('is_api_renewable')->default(false);
            $table->boolean('is_api_transferable')->default(false);
            $table->boolean('is_epp_required')->default(false);
            $table->boolean('is_disable_mod_contact')->default(false);
            $table->boolean('is_disable_wgallot')->default(false);
            $table->string('type')->nullable();
            $table->boolean('is_supports_idn')->default(false);
            $table->boolean('supports_registrar_lock')->default(false);
            $table->integer('add_grace_period_days')->nullable();
            $table->boolean('whois_verification')->default(false);
            $table->boolean('provider_api_delete')->default(false);
            $table->decimal('register_price')->nullable();
            $table->json('register_prices')->nullable();
            $table->decimal('transfer_price')->nullable();
            $table->json('transfer_prices')->nullable();
            $table->decimal('renew_price')->nullable();
            $table->json('renew_prices')->nullable();
            $table->decimal('reactivate_price')->nullable();
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
        Schema::dropIfExists('tlds');
    }
}
