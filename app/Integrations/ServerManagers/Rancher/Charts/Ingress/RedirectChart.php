<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Ingress;

use App\Support\Facades\Application;

class RedirectChart extends IngressChart
{
    public $chart_name = 'redirect';

    public function values()
    {
        $namespace = $this->organization->slug;

        $domains = $this->domain_values();

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'annotations' => [
                    'cert-manager.io/cluster-issuer' => Application::instance($this->app_instance)->configuration('ingress-annotation-cluster_issuer') ?? 'letsencrypt-production',
                    'traefik.ingress.kubernetes.io/router.middlewares' => "$namespace-{$this->name}@kubernetescrd",
                ],
                'namespace' => $namespace,
                'name' => $this->name,
            ],
            'spec' => [
                'rules' => $domains['rules'],
                'tls' => [
                    [
                        'hosts' => $domains['hosts'],
                        'secretName' => "$namespace-{$this->name}-ingress-tls-secret",
                    ],
                ],
            ],
        ];
    }

    private function domain_values()
    {
        $rules = [];
        $hosts = [];
        $service_name = $this->app_instance->getOverride('chart.values.fullNameOverride');

        foreach ($this->domain_names() as $domain_name) {
            $rules[] = [
                'host' => $domain_name,
                'http' => [
                    'paths' => [
                        [
                            'backend' => [
                                'service' => [
                                    'name' => $service_name,
                                    'port' => [
                                        'number' => 8080,
                                    ],
                                ],
                            ],
                            'path' => '/',
                            'pathType' => 'Prefix',
                        ],
                    ],
                ],
            ];

            $hosts[] = $domain_name;
        }

        return [
            'rules' => $rules,
            'hosts' => $hosts,
        ];
    }

    private function domain_names()
    {
        $org_domains = $this->app_instance->domains()->get();
        $app_instance_primary_domain = $this->app_instance->primary_domain;
        $domains = [];

        if ($app_instance_primary_domain) {
            $domains[] = $this->app_instance->base_domain();
        }

        foreach ($org_domains as $domain) {
            if ($domain->id != $this->app_instance->primary_domain_id) {
                $domains[] = $domain->name;
            }
        }

        return $domains;
    }
}
