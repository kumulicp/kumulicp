<?php

namespace App\Integrations\SSO\Authentik;

use App\Integrations\SSO\Authentik\Interfaces\AuthentikSSOInterface;
use Illuminate\Support\Arr;

class AuthentikProfile
{
    private $interfaces = [
        'sso' => AuthentikSSOInterface::class,
    ];

    public function description()
    {
        return [
            'host' => __('admin.servers.authentik.host'),
            'address' => __('admin.servers.authentik.address'),
            'api_key' => __('admin.servers.authentik.api_key'),
            'api_secret' => __('admin.servers.authentik.api_secret'),
            'ip' => __('admin.servers.authentik.ip'),
            'internal_address' => __('admin.servers.authentik.internal_address'),
            'settings' => __('admin.servers.authentik.settings'),
            'general' => [
                __('admin.servers.authentik.general_1'),
                __('admin.servers.authentik.general_2'),
                __('admin.servers.authentik.general_3'),
            ],
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
