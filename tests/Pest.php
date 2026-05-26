<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Services\AccountManagerService;
use App\Services\AdditionalStorageService;
use App\Services\UserPermissionsService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Billing;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\Support\Billing\FakeBillingGateway;
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

uses(TestCase::class, RefreshDatabase::class)->in('Browser');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/AccountManager');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Billing');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Applications');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Auth');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Domains');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Profile');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Registrars');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/ServerManagers');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Services');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/SSO');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Subscription');
uses(TestCase::class, RefreshDatabase::class)->in('API');

pest()->beforeEach(function () {
    (new TestSupports)->seed();
    setupFakeServerInterfaces();
})
    ->in('Browser');

pest()->afterEach(function () {
    (new TestSupports)->cleanLdap();
})
    ->in('Feature/AccountManager', 'Feature/Auth');

pest()->beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();
    $this->support->activateDemoApp();
    $this->support->createDemoAppPlans();
    $this->support->createBase2Plan();
    $this->support->addUsers();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
    $this->demoApp = $this->support->demo_app->instances()->first();
})->in('Feature/Subscription');

pest()->afterEach(function () {
    $this->support->cleanLdap();
})->in('Feature/Subscription');

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
    $provider = $driver === 'ldap' ? 'ldap' : 'users';
    Config::set('account_manager.driver', $driver);
    Config::set('auth.guards.web.provider', $provider);
    Auth::forgetGuards();
    app()->instance('account_manager', new AccountManagerService);
    AccountManager::clearResolvedInstances();
}

/**
 * Skip the current test unless the active driver matches $required.
 * Use for operations that only one driver supports (e.g. app permissions are LDAP-only).
 */
function skipUnlessDriver(string $required, ?string $driver = null): void
{
    $driver ??= config('account_manager.driver');

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
function setupBillingDriver(string $driver): void
{
    if ($driver === 'fake') {
        Billing::register('fake', FakeBillingGateway::class);
        config(['billing.default' => 'fake']);
    }
}

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

/*
|--------------------------------------------------------------------------
| Subscription Test Helpers
|--------------------------------------------------------------------------
|
| Shared utilities for tests under tests/Feature/Subscription/.
| These wrap the UserPermissionsService and AdditionalStorageService
| calls that SubscriptionTestCase previously provided as methods.
|
*/

function grantPermission(string $username, int $appId, array $roles): void
{
    $organization = User::where('username', 'demo')->firstOrFail()->organization;
    $user = AccountManager::users()->find($username);
    (new UserPermissionsService)->updatePermissions(
        user: $user,
        user_id: $username,
        organization: $organization,
        permissions_input: [$appId => $roles],
        with_side_effects: false,
    );
}

function setAdditionalStorage(string $username, int $appInstanceId, int $quantity): void
{
    $organization = User::where('username', 'demo')->firstOrFail()->organization;
    $app = AppInstance::find($appInstanceId);
    (new AdditionalStorageService($organization, 'user', $username, $app))
        ->updateQuantity($quantity);
}

/*
|--------------------------------------------------------------------------
| Bulk Edit Plan Test Helpers
|--------------------------------------------------------------------------
*/

function createBulkEditTestPlans(Application $app): array
{
    $plan1 = AppPlan::factory()->create([
        'name' => 'Original Name One',
        'description' => 'Original Desc One',
        'application_id' => $app->id,
        'archive' => false,
        'payment_enabled' => false,
        'domain_enabled' => false,
        'domain_max' => 0,
        'settings' => [
            'server_type' => 'separate',
            'base' => ['max' => 0, 'price' => 5,  'storage' => 10, 'price_id' => 'prod_1_base'],
            'basic' => ['max' => 2, 'name' => 'Basic One', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_1_basic'],
            'storage' => ['max' => 50, 'price' => 1, 'amount' => 5,  'price_id' => 'prod_1_sto'],
            'features' => [],
            'standard' => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_1_std'],
            'configurations' => [],
            'additionalConfigs' => [],
            'expires_after' => 0,
            'trial_for' => 0,
            'admin_access' => false,
        ],
    ]);

    $plan2 = AppPlan::factory()->create([
        'name' => 'Original Name Two',
        'description' => 'Original Desc Two',
        'application_id' => $app->id,
        'archive' => false,
        'payment_enabled' => true,
        'domain_enabled' => false,
        'domain_max' => 0,
        'settings' => [
            'server_type' => 'separate',
            'base' => ['max' => 0, 'price' => 10, 'storage' => 20, 'price_id' => 'prod_2_base'],
            'basic' => ['max' => 4, 'name' => 'Basic Two', 'price' => 4, 'amount' => 2, 'storage' => 2, 'price_id' => 'prod_2_basic'],
            'storage' => ['max' => 100, 'price' => 2, 'amount' => 10, 'price_id' => 'prod_2_sto'],
            'features' => [],
            'standard' => ['max' => 10, 'price' => 6, 'storage' => 4, 'price_id' => 'prod_2_std'],
            'configurations' => [],
            'additionalConfigs' => [],
            'expires_after' => 0,
            'trial_for' => 0,
            'admin_access' => false,
        ],
    ]);

    return [$plan1, $plan2];
}

function bulkEditSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'default' => false,
        'payment_enabled' => false,
        'admin_access' => false,
        'domain_enabled' => false,
        'domain_max' => 0,
        'expires_after' => 0,
        'trial_for' => 0,
        'server_type' => 'separate',
        'web_server' => null,
        'database_server' => null,
        'sso_server' => null,
        'shared_app' => null,
        'displayed_features' => [],
        'base' => ['price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0],
        'standard' => ['price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0],
        'basic' => ['name' => '', 'price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0, 'amount' => 0],
        'storage' => ['price' => 0, 'price_id' => '', 'amount' => 0, 'max' => 0],
    ], $overrides);
}
