<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Middleware;

class RedirectChart extends MiddlewareChart
{
    public $chart_name = 'redirect';

    public function values()
    {
        $namespace = $this->organization->slug;
        $app_instance = $this->app_instance;
        $base_domain = $this->organization->base_domain;

        $list_domains = implode('|', $this->domain_names());

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'namespace' => $namespace,
                'name' => $this->name,
            ],
            'spec' => [
                'redirectRegex' => [
                    'regex' => "^htt(ps|p)://($list_domains)?(.*)",
                    'replacement' => $app_instance->address().'${3}',
                ],
            ],
        ];
    }

    private function domain_names()
    {
        $org_domains = $this->app_instance->domains;
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
