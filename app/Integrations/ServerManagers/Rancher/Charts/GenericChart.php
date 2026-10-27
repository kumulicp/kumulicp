<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Support\Facades\Application;
use Illuminate\Support\Arr;

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

    public function values(): array
    {
        $app_instance = Application::instance($this->app_instance);
        $version = $this->app_instance->version;

        $port = (int) ($version->setting('port') ?: 8080);
        $fullname = $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $this->organization->slug.'-'.$this->chartName());

        $values = [
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

        // Symfony's Yaml::dump() renders an empty PHP array as `{}`, which
        // the chart's schema rejects for these list-typed values. Omit them
        // when empty so the chart's own `[]` defaults apply.
        foreach (['imagePullSecrets', 'env', 'ingress.tls'] as $list) {
            if (! Arr::get($values, $list)) {
                Arr::forget($values, $list);
            }
        }

        return $values;
    }

    // One PVC-backed volume per `persistence-<index>-*` configuration item,
    // each mounted into every container. The chart keeps PVC creation,
    // pod-volume attachment, and container mounts as three deliberately
    // decoupled lists (see its docs/storage.md), so all three have to agree
    // on the same claim name here.
    private function persistence($app_instance, string $fullname): array
    {
        if (! $app_instance->configuration('persistence-enabled')) {
            return [];
        }

        $claims = $volumes = $mounts = [];

        foreach ($app_instance->arrayConfiguration('persistence') as $index => $item) {
            $name = $item['name'] ?? null;
            $mount_path = $item['mountPath'] ?? null;

            if (! $name || ! $mount_path) {
                throw new \Exception("Generic app persistence item $index needs a name and mountPath");
            }

            if (in_array($name, array_column($mounts, 'name')) || in_array($mount_path, array_column($mounts, 'mountPath'))) {
                throw new \Exception("Generic app persistence item $index repeats the name or mountPath of an earlier item");
            }

            $existing_claim = $item['existingClaim'] ?? null;

            if (! $existing_claim) {
                $claims[] = array_filter([
                    'name' => $name,
                    'storage' => (($item['size'] ?? null) ?: $this->appStorage()).'Gi',
                    'accessModes' => [$item['accessMode'] ?? 'ReadWriteOnce'],
                    'storageClassName' => $item['storageClass'] ?? null,
                ]);
            }

            $volumes[] = [
                'name' => $name,
                'persistentVolumeClaim' => ['claimName' => $existing_claim ?: "{$fullname}-{$name}"],
            ];
            $mounts[] = ['name' => $name, 'mountPath' => $mount_path];
        }

        // An empty list would be dumped as `{}`, see values()
        return array_filter(['persistentVolumeClaims' => $claims, 'volumes' => $volumes, 'mounts' => $mounts]);
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
