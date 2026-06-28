<?php

use App\OrgDomain;
use App\OrgServer;
use App\OrgSubdomain;
use App\Server;
use App\Services\DomainService;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

describe('App Domain Update', function () {
    beforeEach(function () {
        $support = new TestSupports;
        $support->activateDemoApp();

        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        $this->demoApp = $support->demo_app->instances()->first();

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

        Queue::fake();

        app()->instance('domain', new class extends DomainService
        {
            public function ipPointsToServer(OrgSubdomain $domain, $server)
            {
                return true;
            }
        });

        $orgDomain = OrgDomain::factory()->create([
            'organization_id' => $this->demoApp->organization_id,
            'type' => 'managed',
            'name' => 'browser-test-domain.example',
        ]);

        $this->subdomain = OrgSubdomain::factory()->create([
            'organization_id' => $this->demoApp->organization_id,
            'parent_domain_id' => $orgDomain->id,
            'host' => '@',
            'name' => $orgDomain->name,
            'type' => 'app',
            'app_instance_id' => null,
        ]);
    });

    it('updates the app label and domain from the edit page', function () {
        visit('/apps/'.$this->demoApp->id.'/edit')
            ->assertSee('Demo App')
            ->fill('#label input', 'Renamed Demo App')
            ->click('#domain')
            ->click('text=browser-test-domain.example')
            ->click('#submit')
            ->assertPathIs('/apps/'.$this->demoApp->id.'/edit')
            ->assertSee('Renamed Demo App has been updated!')
            ->assertValue('#label input', 'Renamed Demo App');

        $this->demoApp->refresh();
        expect($this->demoApp->label)->toBe('Renamed Demo App');
        expect($this->demoApp->primary_domain_id)->toBe($this->subdomain->id);
    });
});
