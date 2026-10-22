<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Support\Facades\Application;

// Values builder for the third-party "generic" chart published at
// https://repo.helmforge.dev (source: https://github.com/helmforgedev/charts/tree/main/charts/generic),
// backing App\Integrations\Applications\GenericAppProfile. That chart
// supports far more than this profile exposes (StatefulSet/DaemonSet/Jobs,
// KEDA, NetworkPolicy, multi-container pods, ...) -- only a
// single-container Deployment with one optional PVC-backed volume and one
// optional Ingress host is wired up here.
class GenericChart extends HelmChart
{
    public $chart_name = 'generic';

    private const VOLUME_NAME = 'data';

    public function values(): array
    {
        $app_instance = Application::instance($this->app_instance);
        $version = $this->app_instance->version;

        $port = (int) ($version->setting('port') ?: 8080);
        $fullname = $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $this->organization->slug.'-'.$this->chartName());

        return [
            'fullnameOverride' => $fullname,
            'replicaCount' => $this->replicaCount(),
            'image' => [
                'repository' => $this->imageRegistry() ? $this->imageRegistry().'/'.$version->setting('image_repo_name') : $version->setting('image_repo_name'),
                'tag' => $version->name,
                'pullPolicy' => $app_instance->configuration('image-pullPolicy'),
            ],
            'imagePullSecrets' => array_map(fn ($name) => ['name' => $name], $this->imagePullSecrets()),
            'containers' => [
                [
                    'name' => 'app',
                    'ports' => [
                        ['containerPort' => $port],
                    ],
                ],
            ],
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
            'service' => [
                'enabled' => true,
                'port' => $port,
                'targetPort' => $port,
            ],
            'persistence' => $this->persistence($app_instance, $fullname),
            'ingress' => [
                'enabled' => $this->appEnabled() && $app_instance->configuration('ingress-enabled'), // If app is disabled, also disable ingress so it's not accessible from internet without deleting app and losing data
                'ingressClassName' => $app_instance->configuration('ingress-className'),
                'annotations' => $app_instance->configuration('ingress-tls') ? [
                    'cert-manager.io/cluster-issuer' => $this->clusterIssuer(),
                ] : [],
                'hosts' => [
                    ['host' => $this->app_instance->domain()],
                ],
                'tls' => $app_instance->configuration('ingress-tls') ? [
                    [
                        'hosts' => [$this->app_instance->domain()],
                        'secretName' => $fullname.'-tls',
                    ],
                ] : [],
            ],
        ];
    }

    // A single PVC-backed volume ('data'), mounted into every container.
    // The chart keeps PVC creation, pod-volume attachment, and container
    // mounts as three deliberately decoupled lists (see its docs/storage.md),
    // so all three have to agree on the same claim name here.
    private function persistence($app_instance, string $fullname): array
    {
        if (! $app_instance->configuration('persistence-enabled')) {
            return [];
        }

        $existing_claim = $app_instance->configuration('persistence-existingClaim');
        $claim_name = $existing_claim ?: "{$fullname}-".self::VOLUME_NAME;

        return [
            'persistentVolumeClaims' => $existing_claim ? [] : [array_filter([
                'name' => self::VOLUME_NAME,
                'storage' => $this->appStorage().'Gi',
                'accessModes' => [$app_instance->configuration('persistence-accessMode')],
                'storageClassName' => $app_instance->configuration('persistence-storageClass'),
            ])],
            'volumes' => [[
                'name' => self::VOLUME_NAME,
                'persistentVolumeClaim' => ['claimName' => $claim_name],
            ]],
            'mounts' => [[
                'name' => self::VOLUME_NAME,
                'mountPath' => $app_instance->configuration('persistence-mountPath'),
            ]],
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
