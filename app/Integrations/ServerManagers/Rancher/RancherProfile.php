<?php

namespace App\Integrations\ServerManagers\Rancher;

use App\Integrations\ServerManagers\Rancher\Interfaces\RancherWebInterface;
use Illuminate\Support\Arr;

class RancherProfile
{
    private $interfaces = [
        'web' => RancherWebInterface::class,
    ];

    public function description()
    {
        return [
            'host' => __('admin.servers.rancher.host'),
            'address' => __('admin.servers.rancher.address'),
            'api_key' => __('admin.servers.rancher.api_key'),
            'api_secret' => __('admin.servers.rancher.api_secret'),
            'ip' => __('admin.servers.rancher.ip'),
            'internal_address' => __('admin.servers.rancher.internal_address'),
            'settings' => __('admin.servers.rancher.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
