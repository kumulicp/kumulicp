---
name: test-development
description: "Always invoke when writing, editing, or reviewing PHP test files, Pest tests, or anything in the tests/ directory."
license: MIT
metadata:
  author: kumulicp
---

## Writing browser tests

Always use `$this->actingAs(User::where('username', 'demo')->firstOrFail())` in `beforeEach()` to log in — do NOT use form-based login (`visit('/login')->fill(...)`). `actingAs()` works in browser tests because pest-plugin-browser runs in-process (same PHP process). Never use `User::factory()->create()` for login — factory users have no organization or AccountManagerService permissions and login will fail.

Combine related browser tests into a single test where it makes sense — for example, a page render check and pre-filled value assertions can be chained in one test, and all update actions on the same form can be a single test:

```php
it('shows the profile page with pre-filled form fields', function () {
    visit('/profile')
        ->assertSee('First Name')
        ->assertValue('#firstName input', 'Demo')
        ->fill('#firstName input', 'Jane')
        ->click('#submit')
        ->assertPathIs('/profile')
        ->assertSee('Profile was updated!');
});
```

Validation tests must not be written in browser tests — they belong in Feature tests only.

New users should always be added using the AccountManager facade with permissions to access the control panel from there as well, if necessary.

All tests should use `assertPathIs()` to verify the correct page is loaded after an action. Never use `assertUrlIs()` — it matches the full URL including host/port which changes with the dynamic test server port.

All tests should use `assertValue()` to verify the correct value is set on form inputs.

Where there are no id attribute set on an element that needs to be clicked for testing, add an `id` attribute to the element so it can be selected directly.

Never assign to `$this->app` in any test — it holds the Laravel application container and overwriting it breaks `actingAs()`, auth, and every other service that calls `$this->app[...]`. Use a distinct name such as `$this->demoApp` or `$this->application` when storing an Eloquent model or any other value that could shadow it.

### Vuestic input selectors

Vuestic UI's `va-input` renders the `id` attribute on a wrapper `<div>`, not on the inner `<input>`. Playwright's `fill()` requires an actual `<input>` element.

- Single typed input on page: use `fill('input[type=email]', ...)` / `fill('input[type=password]', ...)`
- Multiple inputs of same type: scope by wrapper ID — `fill('#username input', ...)`, `fill('#contactEmail input', ...)`
- `va-button` with an `id` (e.g. `#submit`) can be clicked directly — `click()` works on any element, only `fill()` requires actual inputs

### Vuestic va-checkbox selector pattern

`va-checkbox` applies the `id` prop directly to the inner `<input type="checkbox">` which Vuestic hides with CSS. Playwright's `click()` and `check()` fail on hidden inputs.

- Asserting state: `assertChecked('#my-checkbox')` / `assertNotChecked('#my-checkbox')` — reads the hidden input directly
- Toggling: use `script()` as a separate statement (it returns `mixed`, breaking method chains):

```php
$page = visit('/path');
$page->script("document.querySelector('#my-checkbox').closest('.va-checkbox__input-container').click()");
$page->assertChecked('#my-checkbox');
```

### Vuestic va-select (searchable) selector pattern

`va-select` with `searchable` renders its search `<input>` inside a Vuestic dropdown overlay teleported to the document body — `fill('#mySelect input', ...)` will time out.

Correct approach:
1. `click('#mySelect')` — opens the dropdown
2. `click('text=Option Label')` — directly clicks the visible option

Use `fill` only if the option list requires filtering; in that case use `fill('.va-input-wrapper input', ...)` without scoping to the trigger wrapper.

### Success toast and assertDontSee

Flash/success toast messages remain visible briefly after a redirect. `assertDontSee('X')` will fail if the toast still contains that text. Assert the positive empty state instead — e.g. `assertSee('No Billing Managers')` rather than `assertDontSee('test user1')`.

### Browser test setup

The global `beforeEach` in `tests/Pest.php` calls `(new TestSupports())->seed()` for all Browser tests — this is already wired up and runs automatically. Individual tests call additional `TestSupports` methods as needed (e.g. `addUsers()`, `activateDemoApp()`).

`RefreshDatabase` is applied to the Browser suite in `tests/Pest.php`. It works because pest-plugin-browser runs in-process — DB transactions wrap both test code and HTTP requests, so seeded data is visible to the server.

### LDAP cleanup in browser tests

Any browser test that interacts with groups or users **must** call `cleanLdap()` in an `afterEach` hook inside the `describe` block. Without this, LDAP entries from one test bleed into subsequent tests and cause strict-mode violations or assertion failures on group/user list pages.

```php
use Tests\Support\TestSupports;

describe('Groups', function () {
    afterEach(function () {
        (new TestSupports())->cleanLdap();
    });

    // tests...
});
```

This mirrors the scoped `afterEach` already wired for `Feature/AccountManager` and `Feature/Auth` in `tests/Pest.php`. The `cleanLdap()` method is a no-op when the LDAP driver is not active, so it is safe to call unconditionally.

## Writing all tests

When running tests that target basic or minimal access types, make sure the user's organization's plan or app plan has the basic or minimal access type label added.

Whenever a vue or js file is edited, always run `npm run build` to compile the changes.

## Writing Feature Tests

Validation tests belong exclusively in Feature tests, never in Browser tests. Include validation tests for form inputs to ensure the correct values are set and the form is submitted successfully and incorrect values are rejected.

Don't write auth guard tests each route. Only write auth guards tests when a Gate or $this->authorize() method is used to protect a route or controller. Check what conditions are in the gate and test that the correct behavior is enforced.

Only one auth guard test for all admin routes needs to exist at any time. If that successfully blocks non admin users from accessing one route, all other admin routes should be blocked as well.

When expecting redirects during a request, `$this->followingRedirects();` needs to be added before each request where a redirect is expected.

## Account Manager Tests

Tests that touch users, groups, email accounts, or permissions must run against both the `db` and `ldap` account manager drivers without duplicating test code.

### Where tests live

| Directory | Suite | Notes |
|---|---|---|
| `tests/Feature/AccountManager/` | `AccountManagerLdap` | Auth, groups, and any test needing dual-driver coverage |
| `tests/Feature/AccountManager/Subscription/` | `AccountManagerLdap` | Subscription tests that require LDAP (pricing, roles, storage limits) |
| `tests/Feature/Subscription/` | `Feature` | Subscription tests that only need the db driver |
| `tests/Feature/Auth/` | `AccountManagerLdap` | Login and registration tests |

All files under `tests/Feature/AccountManager/` automatically get `TestCase`, `RefreshDatabase`, and LDAP cleanup via a scoped `afterEach`.

### Pattern

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

### Subscription tests with full setup

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

### Key Rules

1. **Always call `setupAccountManagerDriver()` before `seed()`** — the driver env var must be set before seeding so the correct backend is used.
2. **LDAP is cleaned up in teardown, not setup** — `TestSupports::cleanLdap()` deletes the `demo` and `testing` LDAP orgs after each test so no traces are left behind. This happens automatically via a scoped `afterEach` in `Feature/AccountManager/`. Do not add setup-time LDAP cleanup.
3. **LDAP-only operations** must start with `skipUnlessDriver('ldap', $driver)` and use `->with('account_manager_drivers')` — the `db` iteration emits a clean skip rather than a failure.
4. **Do not use `beforeEach` to skip based on driver** — the old pattern of `if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') { $this->markTestSkipped(...); }` in `beforeEach` is replaced by `skipUnlessDriver('ldap', $driver)` + `->with('account_manager_drivers')`.
5. **New account manager tests go in `tests/Feature/AccountManager/`** — picked up automatically by the `AccountManagerLdap` suite.
