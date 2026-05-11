# Account Manager Test Patterns

Tests that touch users, groups, email accounts, or permissions must run against both the `db` and `ldap` account manager drivers without duplicating test code.

## The Two Patterns

### Pest style (preferred for new tests)

All tests under `tests/Feature/AccountManager/` automatically get `TestCase` and `RefreshDatabase`.

```php
use Tests\Support\TestSupports;

// Runs twice: once with 'db', once with 'ldap'.
// The 'ldap' iteration auto-skips if LDAP is unreachable.
it('creates a group', function (string $driver) {
    setupAccountManagerDriver($driver); // must come before seed()
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();

    // assertions...
})->with('account_manager_drivers');

// LDAP-only operation — 'db' iteration emits a clean skip, not a failure.
it('updates app permissions', function (string $driver) {
    skipUnlessDriver($driver, 'ldap');
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->populate();

    // assertions...
})->with('account_manager_drivers');
```

**Global helpers** (defined in `tests/Pest.php`):
- `setupAccountManagerDriver(string $driver)` — swaps the `account_manager` singleton. Always call before `seed()` because `seed()` reads the driver env var to handle LDAP teardown.
- `skipUnlessDriver(string $driver, string $required)` — marks the test skipped when `$driver !== $required`.
- Dataset `'account_manager_drivers'` — resolves to `['db', 'ldap']`.

### PHPUnit class style (for migrating existing tests)

Use the `TestsWithAccountManagerDrivers` trait and a `@dataProvider`.

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\TestsWithAccountManagerDrivers;
use Tests\Support\TestSupports;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    use RefreshDatabase;
    use TestsWithAccountManagerDrivers;

    protected function tearDown(): void
    {
        $this->restoreAccountManagerDriver();
        parent::tearDown();
    }

    /** @dataProvider accountManagerDriverProvider */
    public function test_groups(string $driver): void
    {
        $this->setupAccountManagerDriver($driver); // before seed()
        $support = new TestSupports;
        $support->seed();
        $support->addUsers();

        // assertions...
    }

    /** @dataProvider accountManagerDriverProvider */
    public function test_updates_app_permissions(string $driver): void
    {
        $this->skipIfNotLdap(); // skip 'db' iteration cleanly
        $this->setupAccountManagerDriver($driver);
        $support = new TestSupports;
        $support->seed();

        // assertions...
    }
}
```

**Trait methods** (defined in `tests/Support/Concerns/TestsWithAccountManagerDrivers.php`):
- `setupAccountManagerDriver(string $driver)` — swaps driver + rebinds singleton.
- `restoreAccountManagerDriver()` — call in `tearDown()` to reset the driver for subsequent tests.
- `skipIfNotLdap()` — shorthand for `skipIfNotDriver('ldap')`.
- `skipIfNotDriver(string $required)` — generic version.
- `accountManagerDriverProvider(): array` — returns `[['db'], ['ldap']]` for use as `@dataProvider`.

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

1. **Always call `setupAccountManagerDriver()` before `seed()`** — `seed()` checks the driver to clean up LDAP orgs first.
2. **Always restore in `tearDown()`** when using the PHPUnit class style — the Pest helpers handle this automatically via `RefreshDatabase`.
3. **LDAP-only operations** (app permissions, `addControlPanelAccess`, `addBillingManagerAccess`, etc.) must start with `skipUnlessDriver($driver, 'ldap')` or `$this->skipIfNotLdap()`.
4. **New account manager tests go in `tests/Feature/AccountManager/`** to be picked up by the `AccountManagerLdap` suite.
