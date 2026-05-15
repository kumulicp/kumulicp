# Account Manager Test Patterns

Tests that touch users, groups, email accounts, or permissions must run against both the `db` and `ldap` account manager drivers without duplicating test code.

## Where tests live

| Directory | Suite | Notes |
|---|---|---|
| `tests/Feature/AccountManager/` | `AccountManagerLdap` | Auth, groups, and any test needing dual-driver coverage |
| `tests/Feature/AccountManager/Subscription/` | `AccountManagerLdap` | Subscription tests that require LDAP (pricing, roles, storage limits) |
| `tests/Feature/Subscription/` | `Feature` | Subscription tests that only need the db driver |
| `tests/Feature/Auth/` | `AccountManagerLdap` | Login and registration tests |

All files under `tests/Feature/AccountManager/` automatically get `TestCase`, `RefreshDatabase`, and LDAP cleanup via a scoped `afterEach`.

## Pattern

```php
use Tests\Support\TestSupports;

// Runs twice: once with 'db' (skips), once with 'ldap'.
it('creates a group', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver); // must come before seed()
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();

    // assertions...
})->with('account_manager_drivers');

// Runs twice: once with 'db', once with 'ldap' — both iterations pass.
it('authenticates users via login screen', function (string $driver) {
    setupAccountManagerDriver($driver);
    (new TestSupports)->seed();

    $response = $this->post('/login', ['email' => 'demo@example.com', 'password' => 'demouser']);

    $response->assertRedirect(RouteServiceProvider::HOME);
    $this->assertAuthenticated();
})->with('account_manager_drivers');
```

**Global helpers** (defined in `tests/Pest.php`):
- `setupAccountManagerDriver(string $driver)` — swaps the `account_manager` singleton. Always call before `seed()` because `seed()` reads the driver env var to handle LDAP teardown.
- `skipUnlessDriver(string $required, ?string $driver = null)` — marks the test skipped when the active driver isn't `$required`. Omit `$driver` to read from the env var (useful in non-parameterized tests); pass `$driver` explicitly when using `->with('account_manager_drivers')`.
- Dataset `'account_manager_drivers'` — resolves to `['db', 'ldap']`.

## Subscription tests with full setup

Subscription tests that need LDAP inline their own setup so `setupAccountManagerDriver` can run before `seed()`:

```php
it('enforces a limit', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    // assertions using $support, $admin, $demoApp...
})->with('account_manager_drivers');
```

Subscription tests that only use the db driver stay in `tests/Feature/Subscription/` and use the global `beforeEach` via `$this->support`, `$this->user`, `$this->demoApp`.

## Running Tests

```bash
# Run account manager tests against both drivers (LDAP iterations skip if unavailable)
php artisan test --testsuite=AccountManagerLdap

# Run only against the db driver (fast, no LDAP needed)
ACCOUNTMANAGER_DRIVER=db php artisan test --testsuite=AccountManagerLdap

# Run the full feature suite (unaffected, always uses db)
php artisan test --testsuite=Feature

# Run everything
php artisan test
```

## LDAP in CI

`.github/workflows/test-ldap.yml` runs the `AccountManagerLdap` suite against a real LDAP server using the `osixia/openldap` service container. No extra setup needed — just push and the workflow handles it.

To run locally with Docker:

```bash
docker run -d --name ldap -p 389:389 \
  -e LDAP_ORGANISATION="Demo" \
  -e LDAP_DOMAIN="example.com" \
  -e LDAP_ADMIN_PASSWORD="admin" \
  osixia/openldap:1.5.0

ACCOUNTMANAGER_DRIVER=ldap \
LDAP_HOST=localhost \
LDAP_PORT=389 \
LDAP_USERNAME="cn=admin,dc=example,dc=com" \
LDAP_PASSWORD=admin \
LDAP_BASE_DN="dc=example,dc=com" \
php artisan test --testsuite=AccountManagerLdap
```

## Key Rules

1. **Always call `setupAccountManagerDriver()` before `seed()`** — the driver env var must be set before seeding so the correct backend is used.
2. **LDAP is cleaned up in teardown, not setup** — `TestSupports::cleanLdap()` deletes the `demo` and `testing` LDAP orgs after each test so no traces are left behind. This happens automatically via a scoped `afterEach` in `Feature/AccountManager/`. Do not add setup-time LDAP cleanup.
3. **LDAP-only operations** must start with `skipUnlessDriver('ldap', $driver)` and use `->with('account_manager_drivers')` — the `db` iteration emits a clean skip rather than a failure.
4. **Do not use `beforeEach` to skip based on driver** — the old pattern of `if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') { $this->markTestSkipped(...); }` in `beforeEach` is replaced by `skipUnlessDriver('ldap', $driver)` + `->with('account_manager_drivers')`.
5. **New account manager tests go in `tests/Feature/AccountManager/`** — picked up automatically by the `AccountManagerLdap` suite.
