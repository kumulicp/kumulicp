<?php

use App\Services\AccountManagerService;
use App\Support\Facades\AccountManager;
use Illuminate\Support\Facades\Artisan;
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

pest()->beforeEach(function () {
    (new TestSupports())->seed();
    })
    ->in('Browser');

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

function something()
{
    // ..
}
