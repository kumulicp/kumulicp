<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Support\Facades\Application;

// Values builder for helm/generic-app -- the image-only chart backing
// App\Integrations\Applications\GenericAppProfile. Unlike the other charts
// in this directory, the chart itself isn't a third-party release: it's
// authored in this repo (see helm/generic-app/README.md for how it gets
// published), so its values schema is whatever we defined there.
class GenericChart extends HelmChart
{
    public $chart_name = 'generic-app';

    public function values(): array
    {
        $app_instance = Application::instance($this->app_instance);
        $version = $this->app_instance->version;

        return [
            'replicaCount' => $this->replicaCount(),
            'fullnameOverride' => $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $this->organization->slug.'-'.$this->chartName()),
            'image' => [
                'registry' => $this->imageRegistry(),
                'repository' => $version->setting('image_repo_name'),
                'tag' => $version->name,
                'pullPolicy' => $app_instance->configuration('image-pullPolicy'),
                'pullSecrets' => $this->imagePullSecrets(),
            ],
            'port' => (int) ($version->setting('port') ?: 8080),
            'env' => array_merge($this->extraEnv(), $this->customEnv($app_instance)),
            'resources' => [
                'requests' => [
                    'cpu' => $app_instance->configuration('resources-requests-cpu'),
                    'memory' => $app_instance->configuration('resources-requests-memory'),
                ],
                'limits' => [
                    'cpu' => $app_instance->configuration('resources-limits-cpu'),
                    'memory' => $app_instance->configuration('resources-limits-memory'),
                ],
            ],
            'persistence' => [
                'enabled' => $app_instance->configuration('persistence-enabled'),
                'size' => $this->appStorage().'Gi',
                'storageClass' => $app_instance->configuration('persistence-storageClass'),
                'existingClaim' => $app_instance->configuration('persistence-existingClaim'),
                'accessMode' => $app_instance->configuration('persistence-accessMode'),
                'mountPath' => $app_instance->configuration('persistence-mountPath'),
            ],
            'ingress' => [
                'enabled' => $this->appEnabled() && $app_instance->configuration('ingress-enabled'), // If app is disabled, also disable ingress so it's not accessible from internet without deleting app and losing data
                'hostname' => $this->app_instance->domain(),
                'tls' => $app_instance->configuration('ingress-tls'),
                'className' => $app_instance->configuration('ingress-className'),
                'annotations' => $app_instance->configuration('ingress-tls') ? [
                    'cert-manager.io/cluster-issuer' => $this->clusterIssuer(),
                ] : [],
            ],
        ];
    }

    // Admin-supplied env vars (configuration('env') is a plain name => value
    // map, since that's the friendliest shape for the yaml-textarea config
    // editor) converted to the {name, value} list k8s/extraEnv() both use.
    private function customEnv($app_instance): array
    {
        $env = [];

        foreach ((array) $app_instance->configuration('env') as $name => $value) {
            $env[] = ['name' => $name, 'value' => (string) $value];
        }

        return $env;
    }
}
