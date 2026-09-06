<?php

namespace App\Integrations\ServerManagers\HelmKubernetes;

use App\Integrations\ServerManagers\HelmKubernetes\Interfaces\HelmKubernetesWebInterface;
use Illuminate\Support\Arr;

class HelmKubernetesProfile
{
    private $interfaces = [
        'web' => HelmKubernetesWebInterface::class,
    ];

    public function description()
    {
        return [
            'general' => [
                __('admin.servers.helm_k8s.general_1'),
            ],
            'host' => __('admin.servers.helm_k8s.host'),
            'address' => __('admin.servers.helm_k8s.address'),
            'ca_cert' => __('admin.servers.helm_k8s.ca_cert'),
            'ip' => __('admin.servers.helm_k8s.ip'),
            'internal_address' => __('admin.servers.helm_k8s.internal_address'),
            'api_key' => __('admin.servers.helm_k8s.api_key'),
            'api_secret' => __('admin.servers.helm_k8s.api_secret'),
            'settings' => __('admin.servers.helm_k8s.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
