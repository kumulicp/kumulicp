<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // slug used in URL (google, okta, etc.)
            $table->string('label');
            $table->string('driver'); // socialite driver name
            $table->string('client_id')->nullable();
            $table->string('client_secret', 500)->nullable();
            $table->string('redirect_url')->nullable();
            $table->string('base_url')->nullable(); // for generic providers
            $table->string('scopes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_providers');
    }
};
