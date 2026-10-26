<?php

namespace App\Integrations\ServerManagers\Kubernetes;

use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use Illuminate\Support\Arr;

class KubernetesProfile
{
    private $interfaces = [
        'web' => KubernetesWebInterface::class,
    ];

    public function description()
    {
        return [
            'general' => [
                __('admin.servers.kubernetes.general_1'),
            ],
            'host' => __('admin.servers.kubernetes.host'),
            'address' => __('admin.servers.kubernetes.address'),
            'ca_cert' => __('admin.servers.kubernetes.ca_cert'),
            'ip' => __('admin.servers.kubernetes.ip'),
            'internal_address' => __('admin.servers.kubernetes.internal_address'),
            'api_key' => __('admin.servers.kubernetes.api_key'),
            'api_secret' => __('admin.servers.kubernetes.api_secret'),
            'settings' => __('admin.servers.kubernetes.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
