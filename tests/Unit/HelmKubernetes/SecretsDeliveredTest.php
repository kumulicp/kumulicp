<?php

use App\AppInstance;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Organization;
use App\OrgServer;
use App\Server;

function helmChartOn(?string $interface): HelmChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);

    if ($interface) {
        $org_server = new OrgServer;
        $org_server->setRelation('server', (new Server)->forceFill(['interface' => $interface]));
        $instance->setRelation('web_server', $org_server);
    }

    return new class((new Organization)->forceFill(['slug' => 'demo']), $instance) extends HelmChart
    {
        public function values(): array
        {
            return [];
        }

        public function delivered(): bool
        {
            return $this->secretsDelivered();
        }
    };
}

it('only uses the secret store where the driver creates the Secret', function (?string $interface, bool $expected) {
    expect(helmChartOn($interface)->delivered())->toBe($expected);
})->with([
    'helm_k8s applies the Secret' => ['helm_k8s', true],
    'rancher never applies the Secret' => ['rancher', false],
    'no web server assigned' => [null, false],
]);
