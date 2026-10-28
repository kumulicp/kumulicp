<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\GenericChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application;

it('builds generic chart values from an image-only app instance', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'chart_name' => 'generic',
            'chart_version' => '3.1.5',
            'helm_repo_name' => 'https://repo.helmforge.dev',
            'image_repo_name' => 'library/my-app',
            'image_registry' => 'docker.io',
            'port' => 9000,
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => [
                'env' => ['FOO' => 'bar'],
                'persistence-enabled' => true,
                'ingress-enabled' => true,
                'ingress-tls' => true,
            ],
        ],
    ]);

    $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
    $app_instance = $app_instance_service->get();

    $chart = new GenericChart($organization, $app_instance);
    $values = $chart->values();

    expect($values['image']['repository'])->toBe('docker.io/library/my-app');
    expect($values['image']['tag'])->toBe($version->name);
    expect($values['containers'][0]['ports'][0]['containerPort'])->toBe(9000);
    expect($values['service']['port'])->toBe(9000);
    expect($values['env'])->toContain(['name' => 'FOO', 'value' => 'bar']);

    expect($values['persistence']['persistentVolumeClaims'][0]['storage'])->toBe('2Gi');
    expect($values['persistence']['volumes'][0]['persistentVolumeClaim']['claimName'])
        ->toBe($values['fullnameOverride'].'-data');
    expect($values['persistence']['mounts'][0]['mountPath'])->toBe('/data');

    expect($values['ingress']['enabled'])->toBeTrue();
    expect($values['ingress']['hosts'][0]['host'])->toBe($app_instance->domain());
    expect($values['ingress']['tls'][0]['secretName'])->toBe($values['fullnameOverride'].'-tls');
    expect($values['ingress']['annotations'])->toHaveKey('cert-manager.io/cluster-issuer');
});

it('defaults the port to 8080 and skips persistence/ingress when disabled', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'image_repo_name' => 'library/my-app',
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
        ],
    ]);

    $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
    $app_instance = $app_instance_service->get();

    $chart = new GenericChart($organization, $app_instance);
    $values = $chart->values();

    expect($values['containers'][0]['ports'][0]['containerPort'])->toBe(8080);
    expect($values['persistence'])->toBe([]);
    expect($values['ingress']['enabled'])->toBeFalse();

    // Empty list-typed values must be omitted: Yaml::dump() would render
    // them as `{}`, which the chart's schema rejects.
    expect($values)->not->toHaveKeys(['imagePullSecrets', 'env']);
    expect($values['ingress'])->not->toHaveKey('tls');
});

it('builds one volume per persistence array item', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => ['image_repo_name' => 'library/my-app'],
    ]);

    $extra = fn (string $field, string $type = 'string') => [
        'name' => "persistence-1-$field",
        'type' => $type,
        'persistent' => true,
        'additional' => true,
    ];

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'additionalConfigs' => [
                'persistence-1-name' => $extra('name'),
                'persistence-1-mountPath' => $extra('mountPath'),
                'persistence-1-size' => $extra('size', 'int'),
                'persistence-1-existingClaim' => $extra('existingClaim'),
            ],
            'configurations' => [
                'persistence-enabled' => true,
                'persistence-1-name' => 'uploads',
                'persistence-1-mountPath' => '/uploads',
                'persistence-1-size' => 5,
                'persistence-1-existingClaim' => '',
            ],
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();
    $fullname = (new GenericChart($organization, $app_instance))->values()['fullnameOverride'];
    $values = (new GenericChart($organization, $app_instance))->values();

    expect($values['persistence']['persistentVolumeClaims'])->toHaveCount(2);
    expect($values['persistence']['persistentVolumeClaims'][0]['name'])->toBe('data');
    expect($values['persistence']['persistentVolumeClaims'][1])->toMatchArray(['name' => 'uploads', 'storage' => '5Gi']);
    expect($values['persistence']['volumes'][1]['persistentVolumeClaim']['claimName'])->toBe("$fullname-uploads");
    expect(array_column($values['persistence']['mounts'], 'mountPath'))->toBe(['/data', '/uploads']);

    // The chart consumes the array items itself, so they must not also be set as raw Helm value paths
    expect((new GenericChart($organization, $app_instance))->valuesWithAdditionalConfigs()['persistence'])
        ->toHaveKeys(['persistentVolumeClaims', 'volumes', 'mounts'])
        ->not->toHaveKey(1);

    // An existing claim is mounted but not created
    $app_instance->plan->updateSettings(['configurations' => array_merge(
        $app_instance->plan->setting('configurations'),
        ['persistence-1-existingClaim' => 'shared-uploads'],
    )]);
    $app_instance->plan->save();
    $values = (new GenericChart($organization, $app_instance->fresh()))->values();

    expect($values['persistence']['persistentVolumeClaims'])->toHaveCount(1);
    expect($values['persistence']['volumes'][1]['persistentVolumeClaim']['claimName'])->toBe('shared-uploads');

    // Items must not share a name or mount path
    $app_instance->plan->updateSettings(['configurations' => array_merge(
        $app_instance->plan->setting('configurations'),
        ['persistence-1-name' => 'data'],
    )]);
    $app_instance->plan->save();

    expect(fn () => (new GenericChart($organization, $app_instance->fresh()))->values())
        ->toThrow(Exception::class, 'repeats the name or mountPath');
});

it('wires probes, pod security, ingress annotations and Recreate', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => ['image_repo_name' => 'library/my-app', 'port' => 9000],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => [
                'probe-path' => '/health',
                'security-runAsUser' => 1000,
                'security-fsGroup' => 2000,
                'persistence-enabled' => true,
                'ingress-tls' => true,
                'ingress-annotations' => ['traefik.ingress.kubernetes.io/router.middlewares' => 'default-limit@kubernetescrd'],
            ],
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();
    $values = (new GenericChart($organization, $app_instance))->values();

    expect($values['readinessProbe']['httpGet'])->toBe(['path' => '/health', 'port' => 9000]);
    expect($values['startupProbe']['httpGet'])->toBe(['path' => '/health', 'port' => 9000]);
    expect($values['podSecurityContext'])->toBe(['runAsUser' => 1000, 'fsGroup' => 2000]);
    expect($values['updateStrategy'])->toBe(['type' => 'Recreate', 'rollingUpdate' => null]);

    expect($values['ingress']['annotations'])->toHaveKeys([
        'traefik.ingress.kubernetes.io/router.middlewares',
        'cert-manager.io/cluster-issuer',
    ]);
});

it('leaves probes, pod security, secrets and update strategy to the chart by default', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => ['image_repo_name' => 'library/my-app'],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();
    $values = (new GenericChart($organization, $app_instance))->values();

    expect($values)->not->toHaveKeys([
        'readinessProbe', 'startupProbe', 'podSecurityContext', 'updateStrategy', 'secrets', 'envFrom', 'podAnnotations',
    ]);
});

it('delivers the plan secrets through the Secret and restarts the pods when they change', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => ['image_repo_name' => 'library/my-app'],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => ['env' => ['PLAIN_SETTING' => 'visible']],
        ],
    ]);
    Application::plan($app_plan)->secrets()->update([['name' => 'DB_PASSWORD', 'value' => 's3cret']]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();
    $on = function (string $interface) use ($app_instance, $organization) {
        $app_instance->unsetRelation('plan'); // a deploy loads the plan fresh
        $org_server = new OrgServer;
        $org_server->setRelation('server', (new Server)->forceFill(['interface' => $interface]));
        $app_instance->setRelation('web_server', $org_server);

        return new GenericChart($organization, $app_instance);
    };

    $chart = $on('kubernetes');
    $values = $chart->values();

    // The value stays out of the Helm values, and is only referenced from the pod
    expect(json_encode($values))->not->toContain('s3cret');
    expect($values['env'])->toContain(['name' => 'PLAIN_SETTING', 'value' => 'visible']);
    expect(collect($values['env'])->firstWhere('name', 'DB_PASSWORD')['valueFrom']['secretKeyRef']['key'])->toBe('db-password');
    expect(base64_decode($chart->secrets()->manifest()['data']['db-password']))->toBe('s3cret');
    expect($values)->not->toHaveKeys(['secrets', 'envFrom']);

    // The pods restart when a secret changes, and not otherwise
    $checksum = $values['podAnnotations']['kumulicp.io/secrets-checksum'];
    expect($on('kubernetes')->values()['podAnnotations']['kumulicp.io/secrets-checksum'])->toBe($checksum);

    Application::plan($app_plan)->secrets()->update([['name' => 'DB_PASSWORD', 'value' => 'changed']]);
    expect($on('kubernetes')->values()['podAnnotations']['kumulicp.io/secrets-checksum'])->not->toBe($checksum);

    // Drivers that do not create the Secret keep the plain value, and have no Secret to watch
    $plain = $on('rancher')->values();
    expect(collect($plain['env'])->firstWhere('name', 'DB_PASSWORD'))->toBe(['name' => 'DB_PASSWORD', 'value' => 'changed']);
    expect($plain)->not->toHaveKey('podAnnotations');
});
