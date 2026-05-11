<?php

namespace Tests\Support\Concerns;

use App\Integrations\ServerManagers\Rancher\RancherProfile;
use App\Integrations\SSO\Authentik\AuthentikProfile;
use Tests\Support\ServerManagers\FakeServerManagerProfile;
use Tests\Support\SSO\FakeSSOProfile;

/**
 * PHPUnit class-style companion to setupFakeServerInterfaces() in Pest.php.
 *
 * Usage:
 *   use Tests\Support\Concerns\TestsWithServerInterfaces;
 *
 *   protected function setUp(): void
 *   {
 *       parent::setUp();
 *       $this->setupFakeServerInterfaces();
 *   }
 *
 *   protected function tearDown(): void
 *   {
 *       $this->restoreServerInterfaces();
 *       parent::tearDown();
 *   }
 *
 * For tests that require a real Rancher or Authentik connection, call
 * skipIfNotServerManager('rancher') or skipIfNotSSO('authentik') at the
 * top of the test, then do NOT call setupFakeServerInterfaces().
 */
trait TestsWithServerInterfaces
{
    protected function setupFakeServerInterfaces(): void
    {
        app('server_interfaces')->register('web', 'rancher', FakeServerManagerProfile::class);
        app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);
    }

    protected function restoreServerInterfaces(): void
    {
        app('server_interfaces')->register('web', 'rancher', RancherProfile::class);
        app('server_interfaces')->register('sso', 'authentik', AuthentikProfile::class);
    }

    protected function skipIfNotServerManager(string $required): void
    {
        $driver = env('SERVER_MANAGER', 'fake');
        if ($driver !== $required) {
            $this->markTestSkipped("Requires '{$required}' server manager (SERVER_MANAGER={$required})");
        }
    }

    protected function skipIfNotSSO(string $required): void
    {
        $driver = env('SSO_DRIVER', 'fake');
        if ($driver !== $required) {
            $this->markTestSkipped("Requires '{$required}' SSO driver (SSO_DRIVER={$required})");
        }
    }

    public static function serverManagerDriverProvider(): array
    {
        return [['fake'], ['rancher']];
    }

    public static function ssoDriverProvider(): array
    {
        return [['fake'], ['authentik']];
    }
}
