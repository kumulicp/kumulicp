<?php

namespace App\Integrations\ServerManagers\AppDatabase;

use App\AppInstance;
use App\Contracts\ServerManager\DatabaseContract;
use App\OrgServer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseInterface implements DatabaseContract
{
    private $database = '';

    private $organization;

    public function __construct(OrgServer $server, private ?AppInstance $app_instance = null)
    {
        $this->organization = $server->organization;
        $this->database = $this->app_instance->databasename ?: $this->organization->slug.'_'.$app_instance->name;
        $password = $this->organization->secretpw;

        // The connection is cached by name; a second server in the same
        // process (e.g. a migration's source, then its target) needs a fresh one.
        DB::purge('app_db');

        Config::set('database.connections.app_db', [
            'driver' => 'mysql',
            'host' => $server->server->host,
            'port' => config('database.connections.mysql.port', '3306'),
            'database' => 'information_schema',
            'username' => $server->server->api_key,
            'password' => $server->server->api_secret,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
    }

    public function existsOrganization()
    {
        return true;
    }

    public function exists()
    {
        return count(DB::connection('app_db')->select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$this->database}'")) === 1;
    }

    public function get() {}

    /**
     * Idempotent: safe to call again on a server where an earlier attempt left
     * the database and/or user behind (e.g. a retried database migration).
     * The user's password is always reset to the organization's, which is the
     * one the app's chart is configured with.
     */
    public function add()
    {
        $connection = DB::connection('app_db');
        $password = $connection->getPdo()->quote($this->organization->secretpw);

        $connection->statement("CREATE DATABASE IF NOT EXISTS `{$this->database}`");
        $connection->statement("CREATE USER IF NOT EXISTS `{$this->database}`@'%' IDENTIFIED BY $password");
        $connection->statement("ALTER USER `{$this->database}`@'%' IDENTIFIED BY $password");
        $connection->statement("GRANT ALL PRIVILEGES ON `{$this->database}`.* TO `{$this->database}`@'%'");

        return [
            'id' => null,
            'databasename' => $this->database,
        ];
    }

    public function update() {}

    public function restore() {}

    public function delete()
    {
        if ($this->exists()) {
            DB::connection('app_db')->select("DROP DATABASE `{$this->database}`");
            DB::connection('app_db')->select("DROP USER `{$this->database}`@'%'");
        }
    }
}
