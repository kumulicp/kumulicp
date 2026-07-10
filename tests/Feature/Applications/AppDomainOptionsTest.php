<?php

use App\OrgDomain;
use App\OrgServer;
use App\OrgSubdomain;
use App\Plan;
use App\Server;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $this->support = new TestSupports;
});

describe('Discover review domain options', function () {
    beforeEach(function () {
        $this->support->seed();

        ['app' => $this->application, 'plan' => $this->plan] = $this->support->prepareDemoApp();

        $this->application->enabled = true;
        $this->application->save();

        $basePlan = Plan::where('is_default', true)->firstOrFail();
        $basePlan->app_plans = array_merge($basePlan->app_plans ?? [], [
            'demo_app' => ['max' => 1, 'plans' => [$this->plan->id]],
        ]);
        $basePlan->save();

        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->user);
    });

    it('only lists the system domain option when only base is allowed', function () {
        $this->application->domain_option = ['base'];
        $this->application->save();

        $response = $this->get("/discover/{$this->application->slug}/plans/{$this->plan->id}/review");

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverOverview')
            ->where('domains', fn ($domains) => collect($domains)->pluck('value')->contains('base')
                && ! collect($domains)->pluck('value')->contains('new')
                && ! collect($domains)->pluck('value')->contains('parent'))
        );
    });

    it('only lists the new subdomain option when only subdomains is allowed', function () {
        OrgDomain::factory()->create([
            'organization_id' => $this->user->organization_id,
            'type' => 'managed',
            'status' => 'active',
        ]);

        $this->application->domain_option = ['subdomains'];
        $this->application->save();

        $response = $this->get("/discover/{$this->application->slug}/plans/{$this->plan->id}/review");

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverOverview')
            ->where('domains', fn ($domains) => collect($domains)->pluck('value')->contains('new')
                && ! collect($domains)->pluck('value')->contains('base'))
        );
    });

    it('rejects activation with the base domain type when not allowed', function () {
        $this->application->domain_option = ['subdomains'];
        $this->application->save();

        OrgDomain::factory()->create([
            'organization_id' => $this->user->organization_id,
            'type' => 'managed',
            'status' => 'active',
        ]);

        $response = $this->post("/discover/{$this->application->slug}/plans/{$this->plan->id}/activate", [
            'label' => 'My App',
            'domain' => 'base',
        ]);

        $response->assertSessionHasErrors('domain');
    });

    it('rejects activation with a new subdomain when not allowed', function () {
        $this->application->domain_option = ['base'];
        $this->application->save();

        $response = $this->post("/discover/{$this->application->slug}/plans/{$this->plan->id}/activate", [
            'label' => 'My App',
            'domain' => 'new',
        ]);

        $response->assertSessionHasErrors('domain');
    });

    it('rejects activation with the parent domain type when not allowed', function () {
        $this->application->domain_option = ['base'];
        $this->application->save();

        $response = $this->post("/discover/{$this->application->slug}/plans/{$this->plan->id}/activate", [
            'label' => 'My App',
            'domain' => 'parent',
        ]);

        $response->assertSessionHasErrors('domain');
    });

    it('allows activation with the base domain type when allowed', function () {
        $this->application->domain_option = ['base'];
        $this->application->save();

        $response = $this->post("/discover/{$this->application->slug}/plans/{$this->plan->id}/activate", [
            'label' => 'My App',
            'domain' => 'base',
        ]);

        $response->assertSessionHasNoErrors();
    });
});

describe('Activated app domain options', function () {
    beforeEach(function () {
        $this->support->seed();
        $this->support->activateDemoApp();

        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->user);

        $this->demoApp = $this->support->demo_app->instances()->first();

        $application = $this->demoApp->application;
        $application->can_update_domain = true;
        $application->save();

        $server = Server::factory()->create(['ip' => '203.0.113.10']);
        $orgServer = new OrgServer;
        $orgServer->organization_id = $this->demoApp->organization_id;
        $orgServer->server_id = $server->id;
        $orgServer->save();
        $this->demoApp->web_server_id = $orgServer->id;
        $this->demoApp->save();
    });

    it('only allows adding a custom subdomain when subdomains is allowed', function () {
        $application = $this->demoApp->application;
        $application->domain_option = ['subdomains'];
        $application->save();

        $response = $this->get('/apps/'.$this->demoApp->id.'/edit');

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Apps/AppEdit')
            ->where('can.add_custom_subdomain', true)
        );
    });

    it('does not allow adding a custom subdomain when only base is allowed', function () {
        $application = $this->demoApp->application;
        $application->domain_option = ['base'];
        $application->save();

        $response = $this->get('/apps/'.$this->demoApp->id.'/edit');

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Apps/AppEdit')
            ->where('can.add_custom_subdomain', false)
        );
    });

    it('does not offer domain choices when the app uses the parent domain', function () {
        $application = $this->demoApp->application;
        $application->domain_option = ['parent'];
        $application->save();

        $response = $this->get('/apps/'.$this->demoApp->id.'/edit');

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Apps/AppEdit')
            ->where('domains', [])
        );
    });

    it('rejects switching to a base domain when not allowed', function () {
        $application = $this->demoApp->application;
        $application->domain_option = ['subdomains'];
        $application->save();

        $response = $this->put('/apps/'.$this->demoApp->id, [
            'label' => 'My App',
            'domain' => 0,
        ]);

        $response->assertSessionHasErrors('domain');
    });

    it('rejects adding a new custom subdomain connection when subdomains is not allowed', function () {
        $application = $this->demoApp->application;
        $application->domain_option = ['base'];
        $application->save();

        $parentDomain = OrgDomain::factory()->create([
            'organization_id' => $this->demoApp->organization_id,
            'type' => 'managed',
        ]);

        $response = $this->put('/apps/'.$this->demoApp->id, [
            'label' => 'My App',
            'domain' => 'connection',
            'parent_domain' => $parentDomain->id,
            'subdomain' => 'newsub',
        ]);

        $response->assertSessionHasErrors('domain');
    });
});
