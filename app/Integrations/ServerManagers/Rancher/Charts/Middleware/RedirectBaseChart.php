<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Middleware;

class RedirectBaseChart extends MiddlewareChart
{
    public $chart_name = 'base';

    public function values()
    {
        $namespace = $this->organization->slug;
        $app_instance = $this->app_instance;
        $primary_domain = $app_instance->primary_domain;
        $base_domain = $this->organization->base_domain;

        $base_urls[] = $app_instance->application->slug.'.'.$base_domain->name;

        $base_urls = implode('|', $base_urls);

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'namespace' => $namespace,
                'name' => $this->name,
            ],
            'spec' => [
                'redirectRegex' => [
                    'regex' => "^htt(ps|p)://($base_urls)?(.*)",
                    'replacement' => 'https://'.$primary_domain->name.'${3}',
                ],
            ],
        ];
    }
}
