<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Ingress;

class RedirectBaseChart extends IngressChart
{
    public $chart_name = 'base';

    public function values()
    {
        $namespace = $this->organization->slug;

        $domains = $this->domain_values();

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'annotations' => [
                    'cert-manager.io/cluster-issuer' => 'letsencrypt-production',
                    'traefik.ingress.kubernetes.io/router.middlewares' => "default-middlewares@kubernetescrd,{$namespace}-{$this->name}@kubernetescrd",
                ],
                'namespace' => $namespace,
                'name' => $this->name,
            ],
            'spec' => [
                'rules' => $domains['rules'],
                'tls' => [
                    [
                        'hosts' => $domains['hosts'],
                        'secretName' => "nextcloud-{$namespace}-base-ingress-tls-secret",
                    ],
                ],
            ],
        ];
    }

    private function domain_values()
    {
        $domains = [];
        $rules = [];
        $base_domain = $this->organization->base_domain;
        $service_name = $this->organization->slug.'-'.$this->app_instance->application->slug;

        $domains[] = [
            'name' => $this->app_instance->application->slug.'.'.$base_domain->name,
            'path' => '/',
        ];

        foreach ($domains as $domain) {
            $rules[] = [
                'host' => $domain['name'],
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
                            'path' => $domain['path'],
                            'pathType' => 'Prefix',
                        ],
                    ],
                ],
            ];

            $hosts[] = $domain['name'];
        }

        return [
            'rules' => $rules,
            'hosts' => $hosts,
        ];
    }
}
