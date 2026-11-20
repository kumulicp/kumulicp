# Plan: Auth, Users/Groups/Permissions, LDAP, Stripe Billing → 5/5 (production ready pending manual QA)

## Context

A prior code-quality pass rated four KumuliCP features 4/5 — "solid, but not fully production ready": **Auth**, **Users/Groups/Permissions**, the **LDAP account-manager driver**, and **Stripe billing**. Three research agents then did deep, file:line-cited exploration of each area, and a fourth agent synthesized those findings into a fix plan. This document is the reviewed, decision-final version of that plan.

Goal: close every concrete gap found — security bugs, data-integrity bugs, missing error handling, missing test coverage — so each area is "production ready pending manual/in-app QA" (per the original 1–5 rubric, a 5 means everything achievable through code + automated tests is done; only human click-through testing remains). Two of the findings are net-new capabilities rather than bugfixes (2FA for Auth, a DB↔LDAP drift-reconciliation job) — both are confirmed **in scope** per the user. A few other flagged items are explicitly **out of scope** per the user's calls below.

**Decisions locked in with the user:**
- **2FA/MFA: in scope.** Build TOTP + recovery codes as a real feature addition to Auth (Phase 3 below), not deferred.
- **Dead auth controllers** (`AuthenticatedSessionController`, `RegisteredUserController`, `NewPasswordController`, `PasswordResetLinkController`, `ConfirmablePasswordController`, and the orphaned `LoginRequest.php`): **not touched in this plan** — tracked as a backlog TODO only (see bottom of this doc).
- **Registration enumeration fix: email only.** Genericize the `contact_email`-already-registered leak; leave username/subdomain "already taken" messages as-is (the signup flow needs to tell the user to pick a different subdomain/username to complete signup — lower PII sensitivity than email).
- **LDAP infra: drift reconciliation only.** Build the DB↔LDAP reconciliation command. Skip TLS-in-CI (would need a new TLS-enabled OpenLDAP container/cert fixtures in `.github/workflows/laravel.yml` — deferred as separate infra work).

**Testsuite legend** (confirmed against `phpunit.xml`, and against the CI wiring added in the prior commit to `.github/workflows/laravel.yml` / `laravel-frankenphp.yml`):
- **Feature** = `tests/Feature/**` minus `AccountManager`, `Auth`, `Applications`, `Registrars`, `ServerManagers`, `SSO` — includes `tests/Feature/Billing/**` and `tests/Feature/UsersTest.php`.
- **AccountManagerLdap** = `tests/Feature/AccountManager/**` and `tests/Feature/Auth/**` (Auth tests run here because several exercise both `db`/`ldap` drivers via the `account_manager_drivers` dataset).
- `Unit`, `ApplicationsIntegration`, `RegistrarIntegration`, `SSOIntegration` also run in CI (not directly relevant to these four areas, but new SSO-related tests belong in `SSOIntegration`).
- `tests/Browser/**` and `tests/API/**` do **not** run in CI — new tests must not rely on either for coverage credit.

---

## Phase 1 — Security & data-integrity fixes (ship first; one PR per area, independently mergeable)

### 1A. Auth

1. **SSO null-pointer (uncaught `TypeError` on real traffic).**
   File: `app/Http/Controllers/Auth/SsoController.php:78-84`. For the `eloquent` provider branch, guard the lookup: if `User::where('email', ...)->first()` returns `null` or the found user's `is_allowed` is falsy, throw a `ValidationException` redirecting to `/login` with a friendly error — never dereference a possibly-null `$user`.
   Test (new, `tests/Feature/Auth/SsoTest.php`, AccountManagerLdap suite via the `Auth` directory): `it('rejects SSO callback for eloquent driver when no matching user exists')`, using `tests/Support/SSO/FakeSSO.php` / `FakeSSOProfile.php`.

2. **Password-reset email enumeration (real leak).**
   File: `app/Http/Controllers/Auth/ForgotPasswordController.php` (this is the controller actually wired via `Auth::routes()` — confirmed the dead `PasswordResetLinkController.php` is not in the request path). Override `sendResetLinkEmail(Request $request)` (inherited from `Illuminate\Foundation\Auth\SendsPasswordResetEmails`) so the JSON/redirect response is identical regardless of whether `Password::sendResetLink()` returns `PasswordBroker::RESET_LINK_SENT` or `INVALID_USER` — always show "if that account exists, a reset link has been sent." Log the real broker status server-side via `report()`/`Log::info()` for support visibility.
   Test: `tests/Feature/Auth/PasswordResetTest.php` — `it('returns the same response whether or not the email exists')`.

3. **Cross-tenant group membership vulnerability** (shares root cause with Users/Groups/Permissions #6 below, but is security-critical — ship here).
   File: `app/Integrations/AccountManagers/Database/Group.php:61-89` (`updateManagers`, `updateMembers`). Both resolve `UserModel::where('username', $x)->first()` with **no organization scope** — confirmed by direct read. Scope the lookup to the group's own organization (and its parent org, for suborg membership), mirroring the org/suborg rule already used in `Gate::define('view-user'/'edit-user', ...)` in `app/Providers/AuthServiceProvider.php:242-260`.
   Also fix the adjacent null-pointer, confirmed by direct read at `Group.php:83-84`: `updateMembers()` computes `$member = $this->group->members()->where('user_id', $user->id)->first();` **before** checking `$user` is non-null on line 85 — a stale/mistyped username throws instead of being skipped. Reorder so the null check on `$user` gates the `$user->id` dereference.
   Test (new `tests/Feature/AccountManager/GroupCrossTenantTest.php`, AccountManagerLdap suite, `->with('account_manager_drivers')`): `it('does not attach a user from another organization as group manager or member')`, `it('skips a nonexistent username without throwing')`.

4. **`Users::resetPassword()` mutating action gated by a read-only permission.**
   File: `app/Http/Controllers/Account/Users.php:494-513`. Change `Gate::authorize('view-user', $user)` to `Gate::authorize('edit-user', $user)` — issuing/emailing a password-reset code is a mutation, not a view.
   Test: `tests/Feature/UsersTest.php` — `it('denies triggering a password reset for a user outside the caller\'s organization')`.

5. **Any authenticated org member can create/rename/delete groups.**
   File: `app/Http/Controllers/Account/Groups.php` — `store`, `update`, `destroy` currently call only `Gate::authorize('active')` (checks the org isn't deactivated, not the caller's role). Add the existing `admin` gate (`AuthServiceProvider.php:33-38`, checks `hasControlPanelAdminAccess()`) alongside `active` on these three actions. Leave `index`/`edit`/`show` as read access for all org members (unchanged).
   Test (new `tests/Feature/AccountManager/GroupsAuthorizationTest.php`, AccountManagerLdap suite): `it('denies group creation/deletion for a non-admin org member')`, `it('allows it for an org admin')`.

### 1B. LDAP driver

6. **Unescaped input in DN construction (LDAP injection / entry corruption).**
   File: `app/Ldap/Actions/Dn.php:33-67` (`Dn::create()`). Escape every user-controlled DN component (`$cn`, `$ou`, `$o->slug`) with LdapRecord's `DistinguishedName::escape()` before concatenation — confirmed via repo-wide grep that no escaping currently happens anywhere. Also fix `Dn::split()` (`Dn.php:9-31`), which naively `explode(',')`/`explode('=')`s a DN and will mis-parse any value containing an escaped comma — replace with LdapRecord's own DN parser.
   Test: `tests/Unit/LdapDnTest.php` (new, `Unit` suite, no LDAP container needed) — round-trip `create()`/`split()` for names containing `,`, `+`, `=`, leading/trailing spaces.

7. **Every LDAP write is unguarded — LdapRecord exceptions surface as raw 500s.**
   Files: `app/Integrations/AccountManagers/Ldap/{Accounts,Users,Groups,Group,Permissions,Account}.php`. Add a small domain exception (`app/Exceptions/AccountManagerException.php`, new) and wrap the externally-triggered write paths (methods called from controllers) in try/catch for `LdapRecord\LdapRecordException`, translating to that domain exception so controllers can flash a friendly error instead of a 500. Priority order: `Accounts::create()` (org provisioning, biggest blast radius) → `Account::destroy()` (recursive delete) → `Group::updateMembers`/`updateManagers` → `Permissions::addControlPanelAccess()`.
   Test (new `tests/Feature/AccountManager/LdapErrorHandlingTest.php`, AccountManagerLdap suite): force an unreachable LDAP host (point `ldap.connections.default` at a closed port) and assert each hardened action returns a graceful error, not a 500.

### 1C. Stripe billing

8. **No webhook handling at all — the single biggest blocker.**
   New: `app/Http/Controllers/StripeWebhookController.php`, extending Cashier's `Laravel\Cashier\Http\Controllers\WebhookController`, overriding `handleCustomerSubscriptionUpdated`, `handleCustomerSubscriptionDeleted`, `handleInvoicePaymentFailed`, `handleInvoicePaymentSucceeded`, `handleChargeDisputeCreated` to reconcile `Organization.status` against the incoming Stripe object (orgs already resolve via Cashier's `stripe_id`/`Billable` trait). Publish `config/cashier.php` and wire `webhook.secret` to the already-present-but-unused `STRIPE_WEBHOOK_SECRET` env var (`.env.sample:54`). Register the route in `routes/api.php` (stateless, no CSRF conflict) with Cashier's `VerifyWebhookSignature` middleware applied.
   Test (new `tests/Feature/Billing/Stripe/WebhookTest.php`, Feature suite): sign a test payload with `\Stripe\WebhookSignature::computeSignature`; `it('rejects an invalid signature')`, `it('marks organization past_due on invoice.payment_failed')`, `it('deactivates organization on customer.subscription.deleted')`.

9. **Organization left in an inconsistent state on Stripe failure — the money-path bug.**
   Files: `app/Http/Controllers/Account/Subscription.php:325-345` and `app/Actions/Organizations/SubscriptionUpdate.php:36-77`. Confirmed by direct read: `Subscription::update()` sets `organization->status = 'active'` and saves it, *then* calls `Action::execute(new SubscriptionUpdate(...))`, whose `run()` calls `Billing::update()` with **no surrounding try/catch** — and note the existing `Bus::chain(...)->catch(...)` in that method only covers the chained closure, not the synchronous `Billing::update()` call above it, so it offers no real protection. Wrap `Billing::update()` in try/catch mirroring `InvoiceOrganization::run()`'s existing pattern (`app/Actions/Organizations/InvoiceOrganization.php:32-48`): on failure, set `$task->status = 'failed'`, `$task->error_message`, save, `report($e)`, and revert `organization->status` to its pre-update value (store it in the task's custom values at construction time so `run()` — a static method — can read it back).
   Test (new `tests/Feature/Billing/Stripe/SubscriptionUpdateFailureTest.php`, Feature suite): extend `tests/Support/Billing/FakeBillingGateway.php` with a `shouldThrowOnUpdate` toggle; assert the organization's status is reverted (not left `active`) and the `Task` is `status = 'failed'` with a populated `error_message`.

10. **No idempotency on billing-mutating actions (double-click race).**
    Files: `app/Http/Controllers/Account/PaymentMethod.php`, `app/Http/Controllers/Account/Subscription.php:344`. `Action::task_exists()` already exists (`app/Actions/Action.php:118-140`) but is never called from these paths. Call it before `Action::execute(new SubscriptionUpdate(...))` and short-circuit with "an update is already in progress" if true; additionally wrap the mutating call in `Cache::lock("subscription-update-{$organization->id}", 10)`, reusing the pattern already proven in `Account/Users.php:223-228`.
    Test: `it('does not create a duplicate subscription-update task on a rapid double submit')`.

---

## Phase 2 — Resilience & hardening

### 2A. Auth

11. **No centralized rate limiting; registration has none at all.**
    File: `app/Providers/AppServiceProvider.php` (`boot()`). Add `RateLimiter::for('register', ...)` and `RateLimiter::for('password-reset', ...)` (login already has `ThrottlesLogins`, but centralize it too for a single reviewable policy). Apply the `register` limiter to the `POST /register` route and `password-reset` to `POST /password/email` in `routes/web.php`.
    Test: `tests/Feature/Auth/RegistrationTest.php` — `it('throttles rapid repeated registration attempts')`; `tests/Feature/Auth/PasswordResetTest.php` — `it('throttles rapid repeated reset requests')`.

12. **Concurrent sessions aren't invalidated on logout/password change.**
    File: `bootstrap/app.php:65` — `AuthenticateSession` middleware is commented out in the `web` group. Uncomment it; verify it doesn't conflict with Octane worker-lifetime state (it's stateless per-request by design, but confirm against `config/octane.php`'s scoped bindings) and doesn't affect the separate `auth:api`/Dingo guard used in `routes/api.php`.
    Test: `tests/Feature/Auth/AuthenticationTest.php` — `it('invalidates other sessions after logout')`.

### 2B. LDAP driver

13. **No rollback for multi-step LDAP operations.**
    Files: `app/Integrations/AccountManagers/Ldap/Accounts.php:15-66` (8 unguarded saves for org provisioning: org → admin → 5 OUs → org-admin-group), `Permissions.php:183-233` (`addControlPanelAccess`: DB save → LDAP attach → LDAP save). On failure partway through, delete whatever sub-entries were already created before rethrowing the domain exception from Phase 1 item 7. For `addControlPanelAccess()`, wrap the DB write in a transaction and detach the LDAP group membership if the final save fails.
    Test: `it('cleans up partially created org entries when a mid-provisioning step fails')` — force failure on a later OU creation and assert no orphaned OUs remain.

14. **LDAP timeout is unbounded; no retry on transient failure.**
    File: `config/ldap.php:31` — `'timeout' => env('LDAP_TIMEOUT', 0)` (0 = unbounded). Change the default to a bounded value (e.g. 5s), keep the env override. Add a small retry-with-backoff wrapper (new `app/Ldap/Support/RetryableLdapOperation.php`) around connection-establishing calls, used by `LdapSupport::testConnection()` and the hardened write paths from Phase 1 item 7.
    Test: `tests/Unit/LdapSupportTest.php` — `it('gives up after the configured retry count against an unreachable host')` (uses a closed local port, so it's fast).

15. **DB↔LDAP drift reconciliation (in scope per user decision).**
    New: `app/Console/Commands/ReconcileLdapAccounts.php` — compares `Organization`/`User` DB rows against their LDAP entries (existence + key attribute drift). Dry-run by default; `--fix` flag repairs simple, safe cases (a missing OU, a stale `member` attribute) and reports everything else. Schedule nightly (check whether this app uses `routes/console.php` or `app/Console/Kernel.php` for scheduling — Laravel 13 defaults to `routes/console.php`).
    Test (new `tests/Feature/AccountManager/LdapReconciliationTest.php`, AccountManagerLdap suite): seed a DB user, delete only its LDAP entry to simulate drift, run the command, assert it's reported (and fixed, under `--fix`).

16. **Lost-update race in group membership writes.**
    File: `app/Integrations/AccountManagers/Ldap/Group.php` (`updateMembers`) — reads the full member list then overwrites it, so two concurrent edits silently clobber each other. Guard the read-modify-write with `Cache::lock()`, mirroring the TOCTOU fix already used for user-limit checks in `Account/Users.php:223-228`.
    Test: two sequential `updateMembers` calls in one test simulating the race; assert the second call's write doesn't lose the first's change.

### 2C. Users/Groups/Permissions

17. **Confirmed N+1 on the users list page.**
    Files: `Account/Users.php:50-64`, `app/Integrations/AccountManagers/Database/Users.php:169-184`, `Database/User.php:22-25`. Eager-load `organization` on the base query (`UserModel::with('organization')->...`) so each row's `new User($user)` constructor doesn't lazy-load per row. Note the bigger multiplier is under the `ldap` driver, where the per-row `Gate::allows('delete-user', ...)` closure may re-hit LDAP via `hasControlPanelAdminAccess()` — check `AuthServiceProvider.php:274-293` for repeated calls and hoist any org-manager-level check outside the per-row loop.
    Test: `tests/Feature/UsersTest.php` — seed 25 users, assert query count stays within a fixed bound via `DB::enableQueryLog()` (Feature suite, `db` driver).

18. **Group rename doesn't migrate its storage-extension record — self-documented by the existing test.**
    Files: `app/Integrations/AccountManagers/Database/Group.php` (`updateName`) and `app/Services/AdditionalStorageService.php`. Re-key (or migrate) the `AdditionalStorage` row when a group is renamed, in `Account/Groups.php::update()` right after `updateName()`.
    Test: `tests/Feature/AccountManager/GroupStorageExtensionTest.php:225-254` currently asserts the *buggy* behavior (storage stays under the old name) — flip the assertion to confirm storage follows the rename, and that no duplicate row is left under the old name.

### 2D. Stripe billing

19. **No dunning/past-due recovery** — depends on the webhook infra from Phase 1 item 8. Once webhooks land, handle Stripe's retry-exhaustion event (final `invoice.payment_failed` in a dunning cycle, or `customer.subscription.updated` → `unpaid`/`canceled`) in `StripeWebhookController` to set `Organization.status`/`deactivate_at` and notify billing managers (reuse the notify pattern already in `StripeGateway::sendInvoices()`).
    Test: extend `WebhookTest.php` with `it('deactivates an organization after Stripe exhausts payment retries')`.

20. **Refunds are entirely unimplemented.**
    Files: `app/Contracts/BillingContract.php` (add `refund()`), `app/Integrations/Billing/Stripe/StripeGateway.php` (implement via Cashier/Stripe's Refund API), `tests/Support/Billing/FakeBillingGateway.php` (add a matching no-op so the fake driver's contract stays in sync).
    Test: `tests/Feature/Billing/Stripe/RefundTest.php` (new) — full refund, partial refund, refund of an already-refunded invoice.

---

## Phase 3 — Two-Factor Authentication (new feature, in scope per user decision)

This app uses hand-wired `laravel/ui` controllers (not Fortify), so 2FA is built the same way: custom controllers + routes, not a scaffolding package. Recommend `pragmarx/google2fa` (TOTP) + `bacon/bacon-qr-code` (QR rendering) as new Composer dependencies — lighter than pulling in Fortify just for this.

21. **Data model.** New migration adding `two_factor_secret` (encrypted), `two_factor_recovery_codes` (encrypted JSON array), `two_factor_confirmed_at` to the `users` table.

22. **Setup/management.** New `app/Http/Controllers/Auth/TwoFactorAuthenticationController.php`: `enable()` (generates a secret + QR code, not yet confirmed), `confirm()` (validates the first TOTP code, sets `two_factor_confirmed_at`, generates 8-10 recovery codes), `disable()` (requires password re-confirmation via the existing `ConfirmPasswordController` flow), `regenerateRecoveryCodes()`. Surface this in the existing profile settings area (`app/Http/Controllers/Profile.php` + a new Inertia page alongside the existing profile Vue pages).

23. **Login-time challenge.** Modify `app/Http/Controllers/Auth/LoginController.php` (the controller actually wired): after successful password authentication, if the user has `two_factor_confirmed_at` set, do **not** complete login — store a signed, short-lived "pending 2FA" marker in the session and redirect to a new challenge page instead of calling the trait's `authenticated()` completion. New `app/Http/Controllers/Auth/TwoFactorChallengeController.php` verifies the submitted TOTP code (or a recovery code, which is then invalidated/removed from the stored set) against the pending session marker, then completes login. Add throttling to the challenge endpoint (same `ThrottlesLogins`-style lockout as the password step) so it isn't a brute-force vector.

24. **Tests** (new `tests/Feature/Auth/TwoFactorAuthenticationTest.php`, AccountManagerLdap suite since it lives under `tests/Feature/Auth/`): enabling 2FA and confirming with a valid code; rejecting an invalid confirmation code; full login flow with a correct TOTP code; login rejected with an incorrect code; login accepted and code consumed via a recovery code; disabling 2FA requires password confirmation; challenge endpoint is throttled after repeated failures.

---

## Phase 4 — Coverage backfill (lowest risk; can interleave with Phases 1-3)

- **Auth:** `AuthenticationTest.php` — add a lockout test, a logout test, an `is_allowed = false` denial test, an SSO happy-path test. `RegistrationTest.php` — a duplicate-subdomain race test (two near-simultaneous registrations with the same subdomain create only one org). `PasswordResetTest.php` — the full lifecycle (token issuance → successful consumption → expired-token rejection → already-used-token rejection); today it's a single trivial validation-only test, the single biggest coverage gap found in this area.
- **Users/Groups/Permissions:** new `tests/Feature/AccountManager/CrossTenantAccessTest.php` systematically hitting `/users/{other-org-username}`, `/users/{id}/permissions`, `/groups/{other-org-slug}` as a different-org user and asserting denial on every one — generalizes the specific fixes in Phase 1 items 3-4 into a standing regression suite.
- **LDAP:** character-edge-case tests (usernames/group names/slugs with `,`, `+`, `=`, unicode, leading/trailing spaces) verifying the Phase 1 item 6 escaping fix round-trips correctly; a large-group-membership test; an org-deletion-partial-failure test.
- **Stripe:** a card-decline test asserting the Phase 1 item 9 fix keeps the organization's status consistent when `StripeGateway::update()` throws a `CardException`.

---

## Backlog (tracked, not actioned in this plan)

- **Dead auth controllers**: `app/Http/Controllers/Auth/{AuthenticatedSessionController,RegisteredUserController,NewPasswordController,PasswordResetLinkController,ConfirmablePasswordController}.php` and `app/Http/Requests/Auth/LoginRequest.php` are confirmed unreferenced by any route (the app runs on `laravel/ui`'s `Auth::routes()` wiring to different controllers). Left as-is per user decision — flag as a follow-up TODO: either delete them, or if they're a staged migration path to a different auth stack, port `LoginController.php:108-131`'s throttling into them so they aren't a live footgun if wired in later.
- **LDAP TLS test coverage in CI**: deferred — would need a TLS-enabled OpenLDAP container/cert fixtures added to `.github/workflows/laravel.yml`, out of scope for this plan.
- **Username/subdomain enumeration on registration**: intentionally left as-is (see Context decisions) — the signup flow needs these collisions surfaced to the user.

---

## Critical Files

- `app/Http/Controllers/Auth/{SsoController,ForgotPasswordController,LoginController}.php`
- `app/Http/Controllers/Account/{Groups,Users,Subscription,PaymentMethod}.php`
- `app/Integrations/AccountManagers/Database/Group.php`
- `app/Integrations/AccountManagers/Ldap/{Accounts,Group,Permissions}.php`
- `app/Ldap/Actions/Dn.php`
- `app/Actions/Organizations/SubscriptionUpdate.php`
- `app/Providers/{AppServiceProvider,AuthServiceProvider}.php`
- `bootstrap/app.php`
- `routes/api.php` (new Stripe webhook route)
- New: `app/Http/Controllers/StripeWebhookController.php`, `app/Exceptions/AccountManagerException.php`, `app/Console/Commands/ReconcileLdapAccounts.php`, `app/Http/Controllers/Auth/TwoFactorAuthenticationController.php`, `app/Http/Controllers/Auth/TwoFactorChallengeController.php`

## Verification

- Run the relevant testsuites after each phase: `php artisan test --testsuite=Feature`, `php artisan test --testsuite=AccountManagerLdap` (needs the real OpenLDAP container per `.github/workflows/laravel.yml`), and `php artisan test --testsuite=Unit` for the new `LdapDnTest`/`LdapSupportTest`.
- For the Stripe webhook work specifically, use the Stripe CLI (`stripe trigger invoice.payment_failed`, etc.) against a local tunnel as a manual smoke test in addition to the signed-payload Pest tests.
- For the LDAP reconciliation command, run `php artisan ldap:reconcile` (dry-run) against the CI OpenLDAP container after manually deleting one entry, to confirm drift is detected before trusting `--fix`.
- For 2FA, manually walk the enable → confirm → logout → login-with-TOTP → recovery-code path once in a browser in addition to the automated tests, since this is the area most worth a human click-through before calling it done.
- This plan does not include running the full local `composer install`/`php artisan test` in this sandbox — the environment's outbound GitHub API rate limit blocked a full dev-dependency install earlier in this session (unrelated to these changes); validate via the project's actual CI once each phase's PR is pushed.
