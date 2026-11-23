<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts;

use App\Support\Facades\Application;
use Illuminate\Support\Arr;

// A second release for an app in maintenance mode: the "generic" chart (see
// GenericChart) running the https://github.com/wickerlabs/maintenance image,
// with an ingress at the app's domain. The app's own chart turns its ingress
// off in the meantime (see HelmChart::inMaintenance()), and is otherwise
// unaffected, so nothing of the app is touched and leaving maintenance mode
// is just removing this release and turning its ingress back on.
//
// The chart is not the app's own, so it says where it comes from itself
// (config/maintenance.php) instead of reading the app's version, and never
// carries the app's environment or secrets.
class MaintenanceChart extends HelmChart
{
    public $chart_name = 'maintenance';

    // The release name: <app instance name>-maintenance
    public function chartName()
    {
        return $this->format($this->app_instance->name.'-maintenance');
    }

    public function helmChartName(): ?string
    {
        return config('maintenance.chart.name');
    }

    public function helmChartVersion(): ?string
    {
        $version = config('maintenance.chart.version');

        return $version === null ? null : (string) $version;
    }

    public function helmRepo(): ?string
    {
        if ($this->app_instance->web_server?->server?->interface !== 'rancher') {
            return config('maintenance.chart.repo');
        }

        // Rancher installs from a repository registered in it, by name
        return config('maintenance.chart.rancher_repo')
            ?: throw new \Exception(__('messages.exception.maintenance_rancher_repo'));
    }

    // A public chart
    public function helmRepoSecret()
    {
        return null;
    }

    public function values(): array
    {
        $app_instance = Application::instance($this->app_instance);
        $domain = $this->app_instance->domain();
        $tls = (bool) $app_instance->configuration('ingress-tls');
        $port = (int) config('maintenance.image.port');
        $fullname = $this->chartName();

        $registry = config('maintenance.image.registry');
        $repository = config('maintenance.image.repository');

        $values = [
            'fullnameOverride' => $fullname,
            'replicaCount' => 1,
            'image' => [
                'repository' => $registry ? $registry.'/'.$repository : $repository,
                'tag' => (string) config('maintenance.image.tag'),
                'pullPolicy' => 'IfNotPresent',
            ],
            'containers' => [
                [
                    'name' => 'maintenance',
                    'ports' => [
                        ['containerPort' => $port],
                    ],
                ],
            ],
            'env' => $this->env($port),
            'resources' => [
                'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                'limits' => ['cpu' => '100m', 'memory' => '64Mi'],
            ],
            'service' => [
                'enabled' => true,
                'port' => $port,
                'targetPort' => $port,
            ],
            'ingress' => [
                'enabled' => true,
                'ingressClassName' => $app_instance->configuration('ingress-className') ?: config('maintenance.ingress_class'),
                'annotations' => array_merge((array) $app_instance->configuration('ingress-annotations'), $tls ? [
                    'cert-manager.io/cluster-issuer' => $app_instance->configuration('ingress-annotation-cluster_issuer') ?: $this->clusterIssuer(),
                ] : []),
                'hosts' => [
                    ['host' => $domain],
                ],
                'tls' => $tls ? [
                    [
                        'hosts' => [$domain],
                        'secretName' => $fullname.'-tls',
                    ],
                ] : [],
            ],
        ];

        // Symfony's Yaml::dump() renders an empty PHP array as `{}`, which the
        // chart's schema rejects for these list-typed values (see GenericChart)
        foreach (['ingress.tls', 'ingress.annotations'] as $path) {
            if (! Arr::get($values, $path)) {
                Arr::forget($values, $path);
            }
        }

        return $values;
    }

    // The image sets its page up from its environment, and substitutes it into
    // the page with sed: a value with one of these characters in it would break
    // the page (or let the value run as part of the script).
    private function env(int $port): array
    {
        $title = $this->safe($this->app_instance->label ?: $this->app_instance->application->name);

        $env = array_filter([
            'PORT' => (string) $port,
            'TITLE' => $title ? "$title - Maintenance" : null,
            'TEAM_NAME' => $this->safe($this->organization->name),
            'MAIL_ADDRESS' => $this->safe((string) (config('maintenance.contact_email') ?: config('mail.from.address'))),
        ], fn ($value) => $value !== null && $value !== '');

        $env = array_merge($env, array_map('strval', (array) config('maintenance.env')));

        $list = [];
        foreach ($env as $name => $value) {
            $list[] = ['name' => $name, 'value' => $value];
        }

        return $list;
    }

    private function safe(?string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N} .,@_+\-\']/u', '', (string) $value));
    }
}
