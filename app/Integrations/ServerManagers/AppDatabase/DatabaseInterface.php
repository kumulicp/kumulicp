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
        $this->database = $this->app_instance->database_name ?? $this->organization->slug.'_'.$app_instance->name;
        $password = $this->organization->secretpw;

        Config::set('database.connections.app_db', [
            'driver' => 'mysql',
            'host' => $server->server->host,
            'port' => env('DB_PORT', '3306'),
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

    public function add()
    {
        if (! $this->exists()) {
            $password = $this->organization->secretpw;
            DB::connection('app_db')->select("CREATE DATABASE `{$this->database}`");
            DB::connection('app_db')->select("CREATE USER `{$this->database}`@'%' IDENTIFIED BY '$password'");
            DB::connection('app_db')->select("GRANT ALL PRIVILEGES ON `{$this->database}`.* TO `{$this->database}`@'%'");
        }

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
