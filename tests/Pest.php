<?php

use App\Services\AccountManagerService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Domain;
use App\Support\Facades\ServerInterface;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Registrars\FakeRegistrar;
use Tests\Support\ServerManagers\FakeServerManagerProfile;
use Tests\Support\SSO\FakeSSOProfile;
use Tests\Support\TestSupports;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Browser');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature/AccountManager');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature/Applications');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature/Registrars');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature/ServerManagers');
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature/SSO');

pest()->beforeEach(function () {
    (new TestSupports())->seed();
    })
    ->in('Browser');

pest()->afterEach(function () {
    (new TestSupports())->cleanLdap();
    })
    ->in('Feature/AccountManager');


/*
|--------------------------------------------------------------------------
| Browser Testing
|--------------------------------------------------------------------------
|
| Tests in tests/Browser/ run against a real Chromium browser via
| Playwright. Install browser binaries once with:
|
|   npx playwright install
|
| Then run browser tests with:
|
|   vendor/bin/pest --testsuite=Browser
|
*/

pest()->browser()
    ->timeout(10000);


/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Account Manager Driver Helpers
|--------------------------------------------------------------------------
|
| Tests under tests/Feature/AccountManager/ run against both the 'db' and
| 'ldap' account manager drivers using the 'account_manager_drivers' dataset.
| Operations that are only supported by one driver should call
| skipUnlessDriver() at the top of the test to emit a clean skip rather
| than a failure.
|
*/

// Named dataset — use ->with('account_manager_drivers') on any test.
dataset('account_manager_drivers', ['db', 'ldap']);

/**
 * Swap the AccountManager singleton to the given driver.
 * Must be called before TestSupports::seed() since seed() reads the env var.
 */
function setupAccountManagerDriver(string $driver): void
{
    putenv("ACCOUNTMANAGER_DRIVER={$driver}");
    app()->instance('account_manager', new AccountManagerService());
    AccountManager::clearResolvedInstances();
}

/**
 * Skip the current test unless the active driver matches $required.
 * Use for operations that only one driver supports (e.g. app permissions are LDAP-only).
 */
function skipUnlessDriver(string $driver, string $required): void
{
    if ($driver !== $required) {
        test()->markTestSkipped("Requires '{$required}' account manager driver");
    }
}

/*
|--------------------------------------------------------------------------
| Server Interface Helpers
|--------------------------------------------------------------------------
|
| Tests that run Application* actions (activate, update, upgrade, delete)
| should call setupFakeServerInterfaces() before seeding. This replaces the
| Rancher and Authentik profile classes in ServerInterfaceService with
| in-memory fakes so no real K8s cluster or SSO server is required.
|
| Real-driver tests skip unless SERVER_MANAGER=rancher / SSO_DRIVER=authentik.
|
*/

dataset('server_manager_drivers', ['fake', 'rancher']);
dataset('sso_drivers', ['fake', 'authentik']);

/**
 * Swap ServerInterfaceService profile lookups to use in-memory fakes.
 * Must be called before any action that calls $app_instance->connect().
 */
function setupFakeServerInterfaces(): void
{
    app('server_interfaces')->register('web', 'rancher', FakeServerManagerProfile::class);
    app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);
}

/**
 * Skip unless SERVER_MANAGER env var matches $required (e.g. 'rancher').
 */
function skipUnlessServerManager(string $driver, string $required): void
{
    if ($driver !== $required) {
        test()->markTestSkipped("Requires '{$required}' server manager");
    }
}

/**
 * Skip unless SSO_DRIVER env var matches $required (e.g. 'authentik').
 */
function skipUnlessSSO(string $driver, string $required): void
{
    if ($driver !== $required) {
        test()->markTestSkipped("Requires '{$required}' SSO driver");
    }
}

/*
|--------------------------------------------------------------------------
| Registrar Helpers
|--------------------------------------------------------------------------
|
| Tests under tests/Feature/Registrars/ run against both the 'fake' and
| 'namecheap' registrar drivers. The 'namecheap' iteration skips unless
| REGISTRAR_DRIVER=namecheap is set.
|
*/

dataset('registrar_drivers', ['fake', 'namecheap']);

/**
 * Register a fake or real registrar driver with DomainService.
 * Must be called before any Domain::registrar() call.
 */
function setupRegistrarDriver(string $driver): void
{
    if ($driver === 'fake') {
        Domain::register('fake', FakeRegistrar::class);
    }
}

/**
 * Skip unless REGISTRAR_DRIVER env var matches $required (e.g. 'namecheap').
 */
function skipUnlessRegistrar(string $driver, string $required): void
{
    if ($driver !== $required) {
        test()->markTestSkipped("Requires '{$required}' registrar driver");
    }
}

function something()
{
    // ..
}
