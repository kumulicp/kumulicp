<?php

use App\OrgDomain;
use App\OrgServer;
use App\OrgSubdomain;
use App\Server;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();
    $this->support->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);

    $this->demoApp = $this->support->demo_app->instances()->first();

    $application = $this->demoApp->application;
    $application->domain_option = ['base', 'subdomains', 'primary'];
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

it('updates the app label and keeps the base domain', function () {
    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My Renamed App',
        'domain' => 0,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect('/apps/'.$this->demoApp->id.'/edit');

    $this->demoApp->refresh();
    expect($this->demoApp->label)->toBe('My Renamed App');
    expect($this->demoApp->primary_domain_id)->toBeNull();
});

it('requires a label', function () {
    $response = $this->put('/apps/'.$this->demoApp->id, [
        'domain' => 0,
    ]);

    $response->assertSessionHasErrors('label');
});

it('requires a domain', function () {
    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
    ]);

    $response->assertSessionHasErrors('domain');
});

it('rejects a domain id that does not exist', function () {
    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
        'domain' => 999999,
    ]);

    $response->assertSessionHasErrors('domain');
});

it('requires a subdomain and parent domain when adding a new connection', function () {
    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
        'domain' => 'connection',
    ]);

    $response->assertSessionHasErrors(['subdomain', 'parent_domain']);
});

it('rejects an invalid subdomain label', function () {
    $parentDomain = OrgDomain::factory()->create([
        'organization_id' => $this->demoApp->organization_id,
        'type' => 'managed',
    ]);

    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
        'domain' => 'connection',
        'parent_domain' => $parentDomain->id,
        'subdomain' => 'Not Valid!',
    ]);

    $response->assertSessionHasErrors('subdomain');
});

it('rejects a subdomain that is already taken', function () {
    $parentDomain = OrgDomain::factory()->create([
        'organization_id' => $this->demoApp->organization_id,
        'type' => 'managed',
    ]);

    OrgSubdomain::factory()->create([
        'organization_id' => $this->demoApp->organization_id,
        'parent_domain_id' => $parentDomain->id,
        'host' => 'taken',
        'type' => 'app',
        'app_instance_id' => $this->demoApp->id + 1,
    ]);

    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
        'domain' => 'connection',
        'parent_domain' => $parentDomain->id,
        'subdomain' => 'taken',
    ]);

    $response->assertSessionHasErrors('subdomain');
});

it('updates the primary domain to an existing connected subdomain', function () {
    Queue::fake();

    app()->instance('domain', new class extends \App\Services\DomainService
    {
        public function ipPointsToServer(OrgSubdomain $domain, $server)
        {
            return true;
        }
    });

    $orgDomain = OrgDomain::factory()->create([
        'organization_id' => $this->demoApp->organization_id,
        'type' => 'managed',
    ]);

    $subdomain = OrgSubdomain::factory()->create([
        'organization_id' => $this->demoApp->organization_id,
        'parent_domain_id' => $orgDomain->id,
        'host' => '@',
        'name' => $orgDomain->name,
        'type' => 'app',
        'app_instance_id' => null,
    ]);

    $response = $this->put('/apps/'.$this->demoApp->id, [
        'label' => 'My App',
        'domain' => $subdomain->id,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect('/apps/'.$this->demoApp->id.'/edit');

    $this->demoApp->refresh();
    expect($this->demoApp->primary_domain_id)->toBe($subdomain->id);
});
