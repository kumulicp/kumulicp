<?php

namespace Tests\Support\Concerns;

use App\Services\AccountManagerService;
use App\Support\Facades\AccountManager;

trait TestsWithAccountManagerDrivers
{
    protected string $originalDriver;

    /**
     * Swap the AccountManager driver and rebind the singleton.
     * Call this before TestSupports::seed() since seed() reads ACCOUNTMANAGER_DRIVER.
     */
    protected function setupAccountManagerDriver(string $driver): void
    {
        $this->originalDriver = env('ACCOUNTMANAGER_DRIVER', 'db');
        putenv("ACCOUNTMANAGER_DRIVER={$driver}");
        app()->instance('account_manager', new AccountManagerService());
        AccountManager::clearResolvedInstances();
    }

    protected function restoreAccountManagerDriver(): void
    {
        putenv("ACCOUNTMANAGER_DRIVER={$this->originalDriver}");
        app()->instance('account_manager', new AccountManagerService());
        AccountManager::clearResolvedInstances();
    }

    /**
     * Skip a test unless the current active driver matches the required one.
     * Use this for operations that only one driver supports (e.g. app permissions are LDAP-only).
     */
    protected function skipIfNotDriver(string $required): void
    {
        if (env('ACCOUNTMANAGER_DRIVER', 'db') !== $required) {
            $this->markTestSkipped("Requires '{$required}' account manager driver");
        }
    }

    protected function skipIfNotLdap(): void
    {
        $this->skipIfNotDriver('ldap');
    }

    public static function accountManagerDriverProvider(): array
    {
        return [['db'], ['ldap']];
    }
}
