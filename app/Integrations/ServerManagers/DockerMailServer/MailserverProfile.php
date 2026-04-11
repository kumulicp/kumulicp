<?php

namespace App\Integrations\ServerManagers\DockerMailServer;

use App\Integrations\ServerManagers\DockerMailServer\Interfaces\LdapEmailInterface;
use Illuminate\Support\Arr;

class MailserverProfile
{
    private $interfaces = [
        'email' => LdapEmailInterface::class,
    ];

    public function description()
    {
        return [
            'host' => __('admin.servers.mailserver.host'),
            'address' => __('admin.servers.mailserver.address'),
            'api_key' => __('admin.servers.mailserver.api_key'),
            'api_secret' => __('admin.servers.mailserver.api_secret'),
            'ip' => __('admin.servers.mailserver.ip'),
            'internal_address' => __('admin.servers.mailserver.internal_address'),
            'settings' => __('admin.servers.mailserver.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
