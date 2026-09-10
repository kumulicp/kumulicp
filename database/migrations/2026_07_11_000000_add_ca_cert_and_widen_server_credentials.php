<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Generic, reusable CA certificate field — not itself a
            // credential, so it's neither encrypted nor hidden. Kept as its
            // own column (rather than inside `settings`) because `settings`
            // is edited via single-line inputs and a multi-line PEM would
            // get corrupted there.
            $table->text('ca_cert')->nullable();
        });

        Schema::table('servers', function (Blueprint $table) {
            // Widen from varchar(255): the `encrypted` cast on these columns
            // produces ciphertext well over 255 chars even for short
            // secrets, and the helm_k8s driver also stores a ServiceAccount
            // token or a PEM client cert/key here (see Server field mapping
            // for the helm_k8s interface).
            $table->text('api_key')->change();
            $table->text('api_secret')->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('api_key')->change();
            $table->string('api_secret')->change();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('ca_cert');
        });
    }
};
