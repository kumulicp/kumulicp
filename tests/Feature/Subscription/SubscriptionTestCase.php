<?php

namespace Tests\Feature\Subscription;

use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase as BaseTestCase;

abstract class SubscriptionTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected TestSupports $support;
    protected User $user;
    protected $demoApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = new TestSupports;
        $this->support->seed();
        $this->support->populate();
        $this->support->addUsers();
        $this->support->disableApps();

        $this->user = User::find(1);
        $this->actingAs($this->user);
        $this->demoApp = $this->support->demo_app->instances()->first();
    }

    protected function requiresLdap(): void
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            $this->markTestSkipped('Requires LDAP driver');
        }
    }

    protected function grantPermission(string $username, int $appId, array $roles): void
    {
        $this->post("/users/{$username}/permissions", [
            'permission' => [
                $appId => $roles,
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
    }

    protected function createEmailServer(): Server
    {
        $server = new Server;
        $server->name = 'Email';
        $server->host = 'localhost';
        $server->address = 'localhost';
        $server->api_key = 'localhost';
        $server->api_secret = 'localhost';
        $server->ip = '127.0.0.1';
        $server->type = 'email';
        $server->interface = 'ldap';
        $server->default_email_server = true;
        $server->status = 'active';
        $server->save();

        return $server;
    }
}
