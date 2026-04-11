<?php

namespace App\Services;

use App\Server;

class ServerService
{
    public function __construct(public Server $server) {}

    public static function defaultServer($type)
    {
        switch ($type) {
            case 'web':
                return self::defaultWebServer();
                break;
            case 'email':
                return self::defaultEmailServer();
                break;
            case 'database':
                return self::defaultDatabaseServer();
                break;
        }
    }

    public static function defaultWebServer()
    {
        return Server::where('default_web_server', 1)->first();
    }

    public static function defaultEmailServer()
    {
        return Server::where('default_email_server', 1)->first();
    }

    public static function defaultDatabaseServer()
    {
        return Server::where('default_database_server', 1)->first();
    }
}
