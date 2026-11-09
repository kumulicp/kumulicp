# Plan: Single-organization install mode

Status: proposal. Nothing in this document is implemented yet.

## Goal

At install time an operator chooses **Single organization** or **Multi-tenant**.
In single mode KumuliCP behaves like a self-hosted app launcher for one
organization:

- Every app is available and every plan limit is unlimited, with no plan
  setup required.
- Organization plans, subscriptions, billing and self-registration are gone
  from the UI and the routes.
- Admin and organization views sit in one navigation instead of two separate
  worlds.
- Discover is the single catalog of apps. An app that is not ready to install
  shows what is missing and links straight to the admin page that fixes it.

Multi-tenant installs keep working exactly as today.

## What already exists

| Piece | Where | What it gives us |
|---|---|---|
| Installer creates one org | `app/Http/Controllers/Setup.php` | A `superaccount` org with an "unlimited" `Plan` (`max: null` everywhere) and an admin user. Single mode extends this. |
| The superaccount is already the system org | `Organization::where('type','superaccount')` is used about 20 times | Background actions, version tests, notifications and Stripe all resolve to it. Single mode can reuse it as *the* org. |
| Feature flags | `config/toggle.php`, `EnsureFeatureEnabled` (`toggle:` route middleware), `MenuService`, shared as `$page.props.flags` | A working pattern for "hide the menu item and 404 the routes". |
| Billing can be off | `BillingService::__call` returns false for `isBillable` when no driver is set; `has-billing-account` gate exists | Payment screens already hide when `BILLING_DRIVER` is empty. |
| Admin and org menus are separate | `HandleInertiaRequests::menu()` picks `admin` or `organization` by URL prefix | The seam to merge them. |
| Discover flow | `Account\Discover`, `Pages/Organization/Discover/*` | Already has a deny-message banner with a button (`missing_parent_app`, `plan_limit_reached`). We extend it. |
| Admin app, version and plan pages | `Admin\Applications*`, `routes/web.php` `admin/apps/{app:slug}/...` | Deep-link targets for every "fix it" action. |

### Why Discover is empty for the superaccount today

`Setup::build()` saves the plan with `app_plans = {}`.
`BasePlanService::enabledApps()` only returns apps that have an `app_plans[slug].plans` entry, and the `view-app`,
`activate-app` and `select-app-plan` gates all check the same thing. So the first admin
sees no apps until someone builds a plan by hand.

`isMaxApps()` defaults `app_plans[slug].max` to **1** when the key is missing, and `(int) null` is `0`
when it is present but null. Either way "unlimited" is not expressible today and needs a code change, not just data.

## Design decisions

### 1. Where the mode lives

- A `ServerSetting` called `tenancy_mode` (`single` or `multi`) is written by the installer.
  The setup screen gets an "Installation type" choice.
- `TENANCY_MODE` in `.env` can pre-seed it for headless installs. The value is read once
  by the installer and then stored in the DB, so the DB is the source of truth afterwards.
- A small `Tenancy` service and facade (`Tenancy::isSingle()`, `isMulti()`, `organization()`) follows the existing
  `Settings` facade pattern. The mode is also shared to Vue as `$page.props.tenancy.single`.
- Single to multi is allowed later through the admin settings. Multi to single is only allowed by artisan, and it refuses unless exactly one
  non-test org exists. Existing multi-tenant installs are **not** auto-converted.

### 2. "Unlimited plan" is computed, not stored

Two options:

| | Computed (recommended) | Stored |
|---|---|---|
| Idea | A `SingleTenantBasePlanService extends BasePlanService` answers "yes, enabled, unlimited" | Keep `Plan.app_plans` in sync with every `Application` and `AppPlan` |
| Drift risk | None | Needs sync hooks on app create/enable, plan create/archive, mode switch |
| Blast radius | One class plus a handful of gate lines | Many write paths |
| Apps with no plan | Handled by readiness (see 4) | Looks "disabled", can't show a warning |

`SubscriptionService::base()` and `add('base', ...)` return the single-tenant subclass when
`Tenancy::isSingle()` and the org is the superaccount. The `Plan` row stays, because `Gate::active` needs `$organization->plan`.

The subclass overrides:

- `enabledApps()`: every `Application` (not filtered by `app_plans`).
- `appEnabled()`, `appPlanEnabled()`: true.
- `appPlansList()` and `appPlans()`: all non-archived, non-hidden `AppPlan` rows for the app.
- `isMaxApps()`, `isMaxAppsBroken()`: false.
- `isMax()`: false for every entity, including app-level `AppInstancePlanService::isMax`, which falls back to `Subscription::base()->isMax()`.
- `allAvailable()`: just the one plan.

Gate and call-site fixes outside the subclass:

- `select-app-plan` reads `$base_plan->app_plans` directly. Switch it to `appPlansList()` / `appPlanEnabled()`.
- `ApplicationService::availablePlans()` goes through the base plan, so it needs no change.
- Anything else that touches `Plan->app_plans` directly (`updateAppInstancesFromBase`, admin plan pages) is
  multi-only and is not reachable in single mode (see 3).

### 3. Hide, don't delete

Add a `tenancy:multi` route middleware (sibling of `toggle:`) that 404s in single mode. Menus and Vue check `tenancy.single`.

| Surface | Single-mode behavior |
|---|---|
| Org menu: Plans, Organization (the `status == 'new'` onboarding items) | Gone |
| Org menu: **Subscription** group (`/subscription/*`, payment method, billing managers, invoices) | Hidden, routes 404 |
| Org menu: Sub-organizations | Hidden, `toggle:sub-organizations` forced off |
| `has-billing-account` gate | Always deny, `Billing` forced to "no driver", Stripe check skipped in `Setup::settings()` |
| `Account\Applications` deactivate: `Billing::periodEnds()` | Already falls back to `now()` with no billing |
| Admin menu: Organizations list, Services → Plans, Services → Shared apps | Hidden, routes 404 |
| Admin settings: Invoice, Registration (captcha) | Hidden |
| Public registration: `Auth::routes()` register, `public/org/{slug}/register`, "Sign up" link on login | Disabled. New people arrive via Users → Add, SSO or LDAP. |
| Org review step in Discover (suborg dropdown) | Never shown |
| Dashboard trial banner, plan limit alerts | Never shown |
| Onboarding stepper in `AppLayout.vue` (choose plan → activate an app → add users; step 0 links to `/subscription/options`) | Hidden. The Getting started checklist replaces it (see 6). |
| Notifications: new org, billing managers, invoices, trial expiry (`NotifyCpAdminOfNewOrganization`, `NotifyBillingManagers`, `InvoiceOrganization`) | Early return |
| Delete or deactivate the only org | Blocked everywhere (`Admin\Organizations@remove/deactivate`, `DeleteOrganization`, `unsubscribe` gate) |
| Per-user "basic vs standard" user types and additional-storage upsells | Hidden. Everyone is `standard`. |

App-level plans (`AppPlan`, "pick Small or Large when installing") are **kept**. Only the *organization* plan goes away.
In single mode these could be relabeled "Profiles" in the copy, as polish.

### 4. App readiness (powers the Discover warnings)

New `ApplicationReadiness` service (`Application::readiness()`) returns an ordered checklist. Each item has
`key`, `ok`, `severity`, `message` and `action: {label, url}`. The URLs are existing admin routes.

| Check | Fails when | Link target |
|---|---|---|
| `enabled` | `applications.enabled = 0` | `/admin/apps/{slug}/edit` (enable toggle) |
| `version` | No `AppVersion` exists | `/admin/apps/{slug}/versions/create` |
| `version_active` | Versions exist but none `active`. This usually means untested, see `AppVersion::canBeEnabled()` and the tests flow. | `/admin/apps/{slug}/versions/{name}` (tests, enable) |
| `plan` | No non-archived `AppPlan` | `/admin/apps/{slug}/plans/create`, plus a **"Create default plan"** button that runs the same logic as `Admin\Applications::store()` |
| `plan_features` | Default plan has no features or configurations saved, where the app's profile defines some | `/admin/apps/{slug}/plans/{id}/features`, `.../configurations` |
| `plan_servers` | Plan lacks `web_server_id` or `database_server_id` that the app needs | `/admin/apps/{slug}/plans/{id}/edit` |
| `servers` | No usable servers at all | `/admin/server/servers` |
| existing | `missing_parent_app`, primary domain | Existing behavior |

Open item: the exact list for `plan_features` and `plan_servers` must come from auditing `ApplicationActivate`,
`AppPlanService` and the app profiles, so it matches what a deploy truly needs. Draft only.

**Where it shows**

- `Discover::index`: every app card gets a state badge (*Installed ×N*, *Ready*, *Needs setup · 2 issues*, *Disabled*).
  Admins see everything. Non-admins see only ready apps.
- `Discover::show`: a warning panel with the checklist and one button per failing item. Non-admins get
  "This app isn't available yet. Ask an administrator." The activate button is disabled until ready.
- `Gate::activate-app` gets a new deny code `app_not_ready`, and `Discover::plans/review/activate` re-check it
  server-side, so the UI is not the only guard.
- The same checklist feeds the admin app page header, so the fix flow and the status stay in one place.

### 5. One navigation instead of two

`HandleInertiaRequests::menu()` stops switching on `/admin` in single mode and builds one menu for admins:

```
Dashboard
Apps            (installed instances)
Discover        (catalog + readiness)  ← new top-level item; today it is only reachable from a button on the Apps page
Users
Groups
Settings        (organization, domains, email)
── Administration ─────────────  (admins only; section header)
Servers · Backups · Tasks · Logs · Security · Packages · System settings
```

- Non-admins never see the Administration section (gate stays `can:admin`).
- `MenuAccordion` needs a section-header item type and active-state matching that treats `/admin/...` as part of the same menu.
- The menu cache key (`org_menu:{locale}`) must include admin status and mode. It currently doesn't, and
  `FastCache` is keyed per-org only, so one admin and one non-admin in the same org would share a menu.
- App cards and the Apps list get an admin-only **Manage** button leading to the admin app/plan/version pages.
  The admin app page gets a "View in Discover" link. The two sides link both ways.
- `/admin/organizations` redirects to the single org's page. Its tabs (apps, backups, logs, tasks, domains) are folded into
  the main menu items, filtered to the one org, so the admin per-org screens are reused instead of rewritten.
  Subscription actions on that page are hidden.

### 6. Installer

- `Setup::settings()`: skip the Stripe check in single mode. Add "Installation type" and an **Organization name** field
  (today it hardcodes `__('setup.first_org')`).
- `Setup::build()`: in single mode, save `tenancy_mode`, use the entered org name, create the same `superaccount`
  + unlimited `Plan` + admin user as today, and mark the org `active` with `step = 4` (already done) so nobody lands on the "new org" welcome flow.
- After install, redirect an admin straight to a **Getting started** checklist on the dashboard:
  servers added, first app ready, domain set, SSO/LDAP (optional). This replaces the multi-tenant `Welcome` page.

### 7. More ways to make it feel single-tenant

- **Branding**: show the org name and logo in the navbar instead of "Welcome to Control Panel". The theme colors already exist in `Settings`.
- **Dashboard**: merge in the admin signals an operator wants every day (failed tasks, security findings, apps with
  updates available). Drop the multi-tenant admin widgets (org counts, plan subscribers, new orgs).
- **Backups**: the per-org backup screens under Admin → Organizations become the main "Backups" page.
- **Support**: the help-desk modal currently files a ticket to the control-panel operator. In single mode the operator
  is the user, so make it a link to the docs URL or an internal contact. Confirm with `Account\Support` before changing.
- **Version-test organizations** stay hidden (`Organization::notTest()`), so test runs never show up as tenants.
- **README and docs**: add an "Installation modes" section.

## Phased delivery

Each phase ships independently and keeps multi-tenant behavior unchanged.

1. **Foundation.** `Tenancy` service, `tenancy_mode` setting, Inertia prop, installer choice, Pest helpers.
2. **Unlimited plan.** `SingleTenantBasePlanService`, gate fixes, unlimited semantics. Discover now lists apps for the superaccount.
3. **Hide multi-tenant surfaces.** `tenancy:multi` middleware, menu items, registration, billing, org delete guards, notification no-ops.
4. **Readiness and Discover.** `ApplicationReadiness`, Discover badges, warning panel with deep links, "Create default plan", server-side guards.
5. **Unified navigation.** Single admin+org menu, section header, menu cache key, Manage and View-in-Discover links, `/admin/organizations` redirect.
6. **Dashboard, Getting started, polish.** Merged dashboard, branding, support, copy.
7. **Docs and upgrade.** README section, `artisan kumulicp:tenancy` command with its safety check, changelog.

## Testing

Pest feature tests (the repo has `tests/Feature/{Admin,Subscription,Applications,Organization,Billing}`):

- Setup in both modes (`SetupRedirectTest` pattern): single mode skips Stripe, stores `tenancy_mode`, creates one active org.
- Plan service: single-mode base plan reports every app enabled and `isMaxApps()` false. Multi-mode results are unchanged (regression).
- Gates: `view-app`, `activate-app`, `select-app-plan` allow in single mode regardless of `app_plans`, and still deny correctly in multi mode.
- Route guards: `/subscription/*`, `/register`, `/admin/service/plans` return 404 in single mode and 200 in multi.
- Menu: admin and non-admin menus in both modes, and the cache doesn't leak across admins.
- Readiness: one test per failing check, asserting the deep-link URL is a registered route.
- Discover: admin vs non-admin visibility, activation refused server-side when not ready.
- Org delete guard: cannot remove, deactivate or unsubscribe the only org.

## Open questions

1. **Who may install apps in single mode?** Today `activate-app` has no admin check, so any org user can. Recommend: admins install, regular users only see apps they have access to. Needs a decision.
2. **Mode switching.** Is single to multi in admin settings wanted, or install-time only?
3. **Naming.** Rename app-level "Plans" to "Profiles" in single mode, or keep "Plans"?
4. **Shared apps** (`toggle:shared-apps`) are cross-org by definition. The plan treats them as unavailable in single mode. Confirm.
5. **Readiness strictness.** Should "plan has no features or configurations" block installs, or only warn?

## Risks

- Anything reading `Plan->app_plans` or `->settings` directly bypasses the computed subclass. A grep audit is needed in phase 2.
- `FastCache` is keyed by org slug only, so any new cached value that varies by admin status or mode needs that in its key (menu, readiness).
- Version-test and background actions look up the `superaccount` by type. Single mode must keep creating it with that type.
