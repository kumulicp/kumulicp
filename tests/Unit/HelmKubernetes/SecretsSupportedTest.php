<?php

use App\AppInstance;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Organization;
use App\OrgServer;
use App\Server;

function chartFor(?string $interface, ?string $chart_version): HelmChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);

    if ($interface) {
        $org_server = new OrgServer;
        $org_server->setRelation('server', (new Server)->forceFill(['interface' => $interface]));
        $instance->setRelation('web_server', $org_server);
    }

    $instance->setRelation('version', (new AppVersion)->forceFill([
        'settings' => $chart_version ? ['chart_version' => $chart_version] : [],
    ]));

    return new class((new Organization)->forceFill(['slug' => 'demo']), $instance) extends HelmChart
    {
        public function values(): array
        {
            return [];
        }

        public function supported(string $min): bool
        {
            return $this->secretsSupported($min);
        }
    };
}

it('only uses the secret store on helm_k8s with a new enough chart', function (?string $interface, ?string $chart_version, bool $expected) {
    expect(chartFor($interface, $chart_version)->supported('0.4.2'))->toBe($expected);
})->with([
    'helm_k8s, exact minimum' => ['helm_k8s', '0.4.2', true],
    'helm_k8s, newer' => ['helm_k8s', '0.5.0', true],
    'helm_k8s, numeric not lexical (0.10.0 > 0.4.2)' => ['helm_k8s', '0.10.0', true],
    'helm_k8s, older chart' => ['helm_k8s', '0.4.1', false],
    'helm_k8s, no chart version recorded' => ['helm_k8s', null, false],
    'rancher never applies the Secret' => ['rancher', '0.4.2', false],
    'no web server assigned' => [null, '0.4.2', false],
]);
