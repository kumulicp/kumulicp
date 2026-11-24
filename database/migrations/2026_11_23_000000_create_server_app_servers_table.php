<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which app servers (web servers) can reach a database or SSO server. A database server is
 * reached over the network the app servers share with it, so it has to be said which they are;
 * an SSO server is usually on the internet, so one with none is reachable from all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_app_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('app_server_id');
            $table->timestamps();
            $table->unique(['server_id', 'app_server_id']);
            $table->index('app_server_id');
        });

        $this->backfill();
    }

    /**
     * Until now every app server could use every database server, so each existing one is given all of
     * them: nothing stops working, and narrowing it down is up to the administrator. SSO servers are
     * left with none, which is reachable from all.
     */
    public function backfill(): void
    {
        $app_servers = DB::table('servers')->where('type', 'web')->pluck('id');

        foreach (DB::table('servers')->where('type', 'database')->pluck('id') as $database_id) {
            foreach ($app_servers as $app_server_id) {
                DB::table('server_app_servers')->insertOrIgnore([
                    'server_id' => $database_id,
                    'app_server_id' => $app_server_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_app_servers');
    }
};
