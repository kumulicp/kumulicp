<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Cluster connection details for the direct helm/kubectl driver.
            // Not secret: only used to reach and verify the API server.
            $table->string('k8s_api_server')->nullable();
            $table->text('k8s_ca_cert')->nullable();
            $table->boolean('k8s_tls_verify')->default(true);
            $table->string('k8s_ingress_class')->nullable();

            // Auth discriminator: 'bearer_token' or 'client_cert'.
            $table->string('k8s_auth_type')->nullable();

            // Credentials — encrypted at the model layer, never returned to the frontend.
            $table->text('k8s_bearer_token')->nullable();
            $table->text('k8s_client_cert')->nullable();
            $table->text('k8s_client_key')->nullable();

            // Optional impersonation.
            $table->string('k8s_impersonate_user')->nullable();
            $table->string('k8s_impersonate_group')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'k8s_api_server',
                'k8s_ca_cert',
                'k8s_tls_verify',
                'k8s_ingress_class',
                'k8s_auth_type',
                'k8s_bearer_token',
                'k8s_client_cert',
                'k8s_client_key',
                'k8s_impersonate_user',
                'k8s_impersonate_group',
            ]);
        });
    }
};
