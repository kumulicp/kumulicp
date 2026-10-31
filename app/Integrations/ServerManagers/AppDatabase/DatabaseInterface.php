<?php

namespace App\Integrations\ServerManagers\AppDatabase;

use App\AppInstance;
use App\Contracts\ServerManager\DatabaseContract;
use App\OrgServer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseInterface implements DatabaseContract
{
    // The database name doubles as the user name, and MySQL caps user names at 32 characters.
    public const MAX_NAME_LENGTH = 32;

    private const HASH_LENGTH = 8;

    private $database = '';

    private $organization;

    public function __construct(OrgServer $server, private ?AppInstance $app_instance = null)
    {
        $this->organization = $server->organization;
        $this->database = $this->app_instance->databasename ?: self::generateName($this->organization->slug, $app_instance->name);
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

    /**
     * The name for a new app database (and its user). Names over the limit keep
     * a readable prefix and end in a hash of the full name, so two long names
     * that share a prefix still come out different. Names already stored on an
     * app instance are never regenerated.
     */
    public static function generateName(string $organization_slug, string $app_name): string
    {
        $name = $organization_slug.'_'.$app_name;

        if (mb_strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        $prefix_length = self::MAX_NAME_LENGTH - self::HASH_LENGTH - 1;

        return mb_substr($name, 0, $prefix_length).'_'.substr(hash('sha256', $name), 0, self::HASH_LENGTH);
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    public function existsOrganization()
    {
        return true;
    }

    public function exists()
    {
        return count(DB::connection('app_db')->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->database])) === 1;
    }

    public function get() {}

    /**
     * Idempotent: safe to call again on a server where an earlier attempt left
     * the database and/or user behind (e.g. a retried database migration).
     * The user's password is always reset to the app's own database password,
     * which is the one the app's chart is configured with.
     */
    public function add()
    {
        $connection = DB::connection('app_db');
        $password = $connection->getPdo()->quote($this->app_instance->dbPassword());
        $database = self::quoteIdentifier($this->database);

        $connection->statement("CREATE DATABASE IF NOT EXISTS $database");
        $connection->statement("CREATE USER IF NOT EXISTS $database@'%' IDENTIFIED BY $password");
        $connection->statement("ALTER USER $database@'%' IDENTIFIED BY $password");
        $connection->statement("GRANT ALL PRIVILEGES ON $database.* TO $database@'%'");

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
            $database = self::quoteIdentifier($this->database);

            DB::connection('app_db')->statement("DROP DATABASE $database");
            DB::connection('app_db')->statement("DROP USER $database@'%'");
        }
    }
}
