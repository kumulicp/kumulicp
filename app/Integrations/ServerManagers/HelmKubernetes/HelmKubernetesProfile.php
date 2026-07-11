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
            'k8s_api_server' => __('admin.servers.helm_k8s.k8s_api_server'),
            'k8s_ca_cert' => __('admin.servers.helm_k8s.k8s_ca_cert'),
            'k8s_tls_verify' => __('admin.servers.helm_k8s.k8s_tls_verify'),
            'k8s_ingress_class' => __('admin.servers.helm_k8s.k8s_ingress_class'),
            'k8s_auth_type' => __('admin.servers.helm_k8s.k8s_auth_type'),
            'k8s_bearer_token' => __('admin.servers.helm_k8s.k8s_bearer_token'),
            'k8s_client_cert' => __('admin.servers.helm_k8s.k8s_client_cert'),
            'k8s_client_key' => __('admin.servers.helm_k8s.k8s_client_key'),
            'settings' => __('admin.servers.helm_k8s.settings'),
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }
}
