<?php

use App\Integrations\ServerManagers\Rancher\Charts\Ingress\RedirectChart as IngressRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\RedirectChart as MiddlewareRedirectChart;
use App\OrgDomain;
use App\OrgSubdomain;
use App\User;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;

it('applies ingress and middleware charts on domain change', function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->addUsers();

    $this->withoutExceptionHandling();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);
    $this->followingRedirects();

    $demo_app = $support->demo_app->instances()->first();
    $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);

    $primary_domain = OrgDomain::factory()->create([
        'organization_id' => $demo_app->organization->id,
        'app_instance_id' => $demo_app->id,
        'source' => 'organization',
        'status' => 'active',
    ]);

    $primary_domain = OrgSubdomain::factory()->create([
        'organization_id' => $demo_app->organization->id,
        'app_instance_id' => $demo_app->id,
        'parent_domain_id' => $primary_domain->id,
        'name' => $primary_domain->name,
    ]);

    $demo_app->primary_domain_id = $primary_domain->id;
    $demo_app->save();
    $app_base_domain = $demo_app->base_domain();

    $ingress_chart = (new IngressRedirectChart($demo_app->organization, $demo_app))->values();
    expect(Arr::get($ingress_chart, 'spec.rules.0.host'))->toContain($app_base_domain);

    $middleware_chart = (new MiddlewareRedirectChart($demo_app->organization, $demo_app))->values();
    expect(Arr::get($middleware_chart, 'spec.redirectRegex.regex'))->toContain($app_base_domain);
    expect(Arr::get($middleware_chart, 'spec.redirectRegex.replacement'))->toContain($demo_app->domain());

    $demo_app->primary_domain_id = 0;
    $demo_app->save();
    $demo_app->refresh();

    $ingress_chart = (new IngressRedirectChart($demo_app->organization, $demo_app))->values();
    expect(Arr::get($ingress_chart, 'spec.rules.0.host'))->toContain($primary_domain->name);

    $middleware_chart = (new MiddlewareRedirectChart($demo_app->organization, $demo_app))->values();
    expect(Arr::get($middleware_chart, 'spec.redirectRegex.regex'))->toContain($primary_domain->name);
    expect(Arr::get($middleware_chart, 'spec.redirectRegex.replacement'))->toContain($demo_app->domain());
});
