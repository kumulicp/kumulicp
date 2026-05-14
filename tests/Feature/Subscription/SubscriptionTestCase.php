<?php

namespace Tests\Feature\Subscription;

use App\AppInstance;
use App\Server;
use App\Services\AdditionalStorageService;
use App\Services\UserPermissionsService;
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

    protected function tearDown(): void
    {
        $this->support->cleanLdap();
        parent::tearDown();
    }

    protected function requiresLdap(): void
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            $this->markTestSkipped('Requires LDAP driver');
        }
    }

    protected function grantPermission(string $username, int $appId, array $roles): void
    {
        $user = \App\Support\Facades\AccountManager::users()->find($username);

        app(UserPermissionsService::class)->updatePermissions(
            user: $user,
            user_id: $username,
            organization: $this->user->organization,
            permissions_input: [$appId => $roles],
            with_side_effects: false,
        );
    }

    protected function setAdditionalStorage(string $username, int $appInstanceId, int $quantity): void
    {
        $app = AppInstance::find($appInstanceId);

        (new AdditionalStorageService($this->user->organization, 'user', $username, $app))
            ->updateQuantity($quantity);
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
        $server->internal_address = 'localhost';
        $server->type = 'email';
        $server->interface = 'ldap';
        $server->default_email_server = true;
        $server->status = 'active';
        $server->save();

        return $server;
    }
}
