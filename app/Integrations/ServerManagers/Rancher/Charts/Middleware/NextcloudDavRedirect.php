<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Middleware;

class NextcloudDavRedirect extends MiddlewareChart
{
    public $chart_name = 'dav';

    public function values()
    {
        $namespace = $this->organization->slug;

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'namespace' => $namespace,
                'name' => $this->name,
            ],
            'spec' => [
                'redirectRegex' => [
                    'permanent' => true,
                    'regex' => 'https://(.*)/.well-known/(card|cal)dav',
                    'replacement' => 'https://${1}/remote.php/dav/',
                ],
            ],
        ];
    }
}
