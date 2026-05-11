<?php

namespace Tests\Support\Concerns;

use Tests\Support\ServerManagers\FakeServerManagerProfile;
use Tests\Support\SSO\FakeSSOProfile;

/**
 * PHPUnit class-style companion to the 'sso_drivers' dataset in Pest.php.
 *
 * Usage:
 *   use Tests\Support\Concerns\TestsWithSSODrivers;
 *
 *   /** @dataProvider ssoDriverProvider *\/
 *   public function test_creates_application(string $driver): void
 *   {
 *       $this->setupSSODriver($driver);
 *       // ...
 *   }
 *
 *   protected function tearDown(): void
 *   {
 *       $this->restoreSSO();
 *       parent::tearDown();
 *   }
 */
trait TestsWithSSODrivers
{
    protected function setupSSODriver(string $driver): void
    {
        if ($driver === 'fake') {
            app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);
        }
    }

    protected function restoreSSO(): void
    {
        app('server_interfaces')->register('sso', 'authentik', \App\Integrations\SSO\Authentik\AuthentikProfile::class);
    }

    protected function skipIfNotSSO(string $required): void
    {
        $driver = env('SSO_DRIVER', 'fake');
        if ($driver !== $required) {
            $this->markTestSkipped("Requires '{$required}' SSO driver (SSO_DRIVER={$required})");
        }
    }

    public static function ssoDriverProvider(): array
    {
        return [['fake'], ['authentik']];
    }
}
