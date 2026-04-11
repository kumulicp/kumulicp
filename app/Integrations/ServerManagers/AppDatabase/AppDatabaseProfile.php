<?php

namespace App\Integrations\ServerManagers\AppDatabase;

use Illuminate\Support\Arr;

class AppDatabaseProfile
{
    private $interfaces = [
        'database' => DatabaseInterface::class,
    ];

    public function description()
    {
        return [
            'host' => __('admin.servers.app_database.host'),
            'address' => __('admin.servers.app_database.address'),
            'api_key' => __('admin.servers.app_database.api_key'),
            'api_secret' => __('admin.servers.app_database.api_secret'),
            'ip' => __('admin.servers.app_database.ip'),
            'internal_address' => __('admin.servers.app_database.internal_address'),
            'settings' => __('admin.servers.app_database.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
