<?php

namespace Tests\Feature\Domains;

use App\Integrations\ServerManagers\Rancher\Charts\Ingress\RedirectChart as IngressRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\RedirectChart as MiddlewareRedirectChart;
use App\OrgDomain;
use App\OrgSubdomain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;
use Tests\TestCase;

class RancherDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingress_middleware_on_domain_change()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();

        $this->withoutExceptionHandling();
        $user = User::find(1);
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
        $this->assertStringContainsString($app_base_domain, Arr::get($ingress_chart, 'spec.rules.0.host'));

        $middleware_chart = (new MiddlewareRedirectChart($demo_app->organization, $demo_app))->values();
        $this->assertStringContainsString($app_base_domain, Arr::get($middleware_chart, 'spec.redirectRegex.regex'));
        $this->assertStringContainsString($demo_app->domain(), Arr::get($middleware_chart, 'spec.redirectRegex.replacement'));

        $demo_app->primary_domain_id = 0;
        $demo_app->save();

        $demo_app->refresh();

        $ingress_chart = (new IngressRedirectChart($demo_app->organization, $demo_app))->values();
        $this->assertStringContainsString($primary_domain->name, Arr::get($ingress_chart, 'spec.rules.0.host'));

        $middleware_chart = (new MiddlewareRedirectChart($demo_app->organization, $demo_app))->values();
        $this->assertStringContainsString($primary_domain->name, Arr::get($middleware_chart, 'spec.redirectRegex.regex'));
        $this->assertStringContainsString($demo_app->domain(), Arr::get($middleware_chart, 'spec.redirectRegex.replacement'));
    }
}
