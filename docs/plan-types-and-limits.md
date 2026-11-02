# Plan types (Pay per App vs Packaged) and their limits

Status: proposal. Nothing here is implemented yet.

## 1. How the two plan types work today

`Plan.type` is `package` (shown as "Packaged") or `app` (shown as "Pay per App"). The admin
controller is `app/Http/Controllers/Admin/Plans.php`, the form is
`resources/js/Pages/Admin/Plans/PlanEdit.vue`.

| | Packaged (`package`) | Pay per App (`app`) |
|---|---|---|
| Who bills | The org-level `Plan`: base, per-user, volunteer ("basic"), additional storage, application and email prices (`BasePlanService`) | Each app instance's `AppPlan` (`AppInstancePlanService`). `BasePlanService::pricingOptions()/stats()/totalPrice()` return empty or 0 for this type |
| App plans per app | Exactly one. The controller slices to the first, and `updateAppInstancesFromBase()` pushes it to every instance when the org's plan changes | One or more. The org picks one per instance (`select-app-plan`) |
| Where limits are read | `Subscription::base()->isMax(...)` (org-wide counts) | `Subscription::app_instance($i)->isMax(...)` (per-instance counts), falling back to base when the app plan has no max |
| Base-plan fields saved | All | `base/standard/basic/storage/application` prices and limits are nulled on save. Only email, domains, suborganizations and app entitlements remain |

Limit checks all live in `app/Providers/AuthServiceProvider.php`:
`add-user`, `update-standard-user`, `update-basic-user`, `add-app-user`,
`update-app-standard-user`, `update-app-basic-user`, `activate-app`,
`add-additional-storage`. The counting and comparison code is in
`BasePlanService`, `AppInstancePlanService` and `AdditionalStorageService`.

## 2. Gaps found while reading the code

These are the reasons to put limits in place deliberately instead of leaving them implicit.
All are from reading the code. I have not run the test suite or reproduced them.

1. **"Blank" and "0" mean different things in different places.**
   - `isMax()` and `isMaxBroken()` treat a falsy max (null or 0) as unlimited.
   - `BasePlanService::isMaxApps()` treats `{slug}.max = 0` as "no instances allowed", and the form defaults it to 0. A missing key defaults to 1.
   - `domain_max`: base plan `0` means none allowed (`!is_null` check), app plan `0` means unlimited (`!$domain_max`).
   - The controllers cast blanks with `(int)`, so an emptied field is stored as 0.
2. **Pay per App has no org-wide ceilings.** `standard.max` and `application.max` are nulled on save. `add-user` checks `Subscription::base()->isMax('standard')`, which can never be true for this type, so control-panel users are unlimited. Total apps is unlimited too, bounded only by `{slug}.max` for each app.
3. **Email price is editable but never billed on Pay per App.** The email section of `PlanEdit.vue` is not behind the `package` condition, and `email.*` prices are kept on save. But `BasePlanService::pricingOptions()` returns `[]` for `app`, so Stripe never sees them.
4. **Packaged plans can double-bill if an app plan carries prices.** `SubscriptionService::paidSubscriptions()` includes every app instance plan with `payment_enabled`, and `StripeGateway::stripePricing()` sums all of their `pricingOptions()`. `AppInstancePlanService` has no guard for `package`, unlike `BasePlanService` for `app`. This only happens if an admin gave the app plan prices, but nothing prevents it.
5. **Per-user storage comes from different places.** `AppStorageService::userStorage()` always reads `standard.storage` and `basic.storage` from the app plan. The package form sets them on the base plan (`BasePlanService::standardStorageTotal()` uses those). On a packaged plan the value the admin types in the package form is not what the app gets.
6. **App-plan changes are only handled for packaged plans with one app plan.** `updateAppInstancesFromBase()` skips any app that has more than one offered plan. Nothing handles an instance whose app plan is later removed from the Pay per App plan's list, or a plan-type switch while subscribers exist (the UI only shows a warning).
7. **Storage cap defaults are hidden.** `storage.max` falls back to 10 blocks in `AdditionalStorageService::maxAllowedAdditionalStorage()` when unset, and the dropdown never offers more than 10 per request.

## 3. Principles

- One meaning for each limit value: **null = unlimited, 0 = not allowed, N = cap**. The form needs an explicit "Unlimited" toggle so a blank field is never ambiguous.
- Packaged: the **org plan is the only source** of prices, user limits and per-user storage. App plans only describe what the app needs technically.
- Pay per App: the **app plan owns** prices, user limits and storage per instance. The org plan only owns entitlements (which apps, which app plans, how many instances) and the org-level services that no single app owns (email, domains, suborganizations).
- Every limit has a platform ceiling in config, so a typo cannot create an effectively unlimited priced plan.
- Raising a limit is always safe. Lowering a limit below current usage must be blocked or warn, and existing usage is grandfathered (this is what `isMaxBroken()` does today).

## 4. Proposed limits per setting

The numbers in the "Suggested default" column are **starting points, not business decisions**. They need to be set by whoever owns pricing. "Required" means the admin form should refuse to save a *priced* plan with the field empty. Unpriced and internal plans (such as the archived Setup plan) may stay unlimited.

### Org-level plan (`plans`)

| Setting | Packaged | Pay per App | Suggested default / ceiling |
|---|---|---|---|
| `standard.max` (active users) | **Required.** Org-wide cap | Not used. Cap comes from the app plan. See `application.max` row for the org-level backstop | Tiers, e.g. 10 / 50 / 200. Ceiling 10,000 |
| `basic.max`, `basic.amount` (volunteer users and bundle size) | Required if `basic.name` is set. `max` must be a multiple of `amount` and at least `amount` | Not used | e.g. bundle 5, max 5 to 10 times standard max |
| `standard.storage`, `basic.storage` (GB per user) | **Required.** `basic` must be ≤ `standard`. This value is what apps use (gap 5) | Not used. App plan owns it | e.g. 5 / 1 GB. Ceiling 100 GB |
| `storage.amount`, `storage.max` (add-on block size, max blocks) | Required if add-on price is set. Explicit `max` | Not used. App plan owns it | Block 10 GB, max 10 blocks (matches today's silent default) |
| `application.max` (total active apps) | **Required.** Must be ≥ the largest `{slug}.max` | Optional org-wide ceiling. Re-enable it for this type (it is nulled today). Gives the backstop missing in gap 2 | e.g. 3 / 6 / 12. Ceiling 50 |
| `{slug}.max` (instances per app) | Default 1. Allow more only for apps that make sense to run twice. Must be ≤ `application.max` | Same, per app | Default 1. Ceiling 10 |
| App plans per app | Exactly 1 (already enforced) | 1 or more (already allowed). Each must be non-archived | n/a |
| `email.max` (accounts) | Required when `email_enabled` | Required when `email_enabled`, and **billed** (gap 3) | e.g. 5 / 25 / 100. Ceiling 1,000 |
| `email.storage` (GB per mailbox) | Required when `email_enabled` | Same | e.g. 1 to 5 GB. Ceiling 50 |
| `domain_max` | Required when `domain_enabled`. 0 = none | Same, using the same 0 = none meaning | e.g. 1 / 3 / 10. Ceiling 100 |
| `suborganizations.enabled` | Allowed. Joint invoicing counts suborgs against the parent's limits (`JointSubscriptionService`) | Allowed. Each suborg keeps its own plan | Off by default |
| Prices (`base`, `standard`, `basic`, `storage`, `application`) | Allowed | **Blocked** (already nulled) | n/a |
| Prices (`email`) | Allowed | Allowed and billed at the org level | n/a |

### App plan (`app_plans`)

| Setting | Packaged | Pay per App | Suggested default / ceiling |
|---|---|---|---|
| Prices (`base`, `standard`, `basic`, `storage`, features) | **Blocked.** Ignore and don't bill (gap 4) | Allowed | n/a |
| `standard.max`, `basic.max` (users in this app) | Optional technical ceiling only. The org-wide limit governs | **Required** per tier | e.g. 10 / 50 / 250 per tier. Ceiling 10,000 |
| `standard.storage`, `basic.storage` | Ignored. Org plan values apply | **Required.** `basic` ≤ `standard` | Same as org-level defaults |
| `storage.amount`, `storage.max` | Ignored. Org plan values apply | Required if an add-on price is set. Explicit `max` | Block 10 GB, max 10 blocks |
| `domain_max` | Ignored. Org plan value applies | Optional. 0 = none, null = unlimited | Ceiling 100 |
| Optional features (priced) | Allowed only if the feature price is blank (feature is then on or off by the app plan) | Allowed with prices | n/a |

## 5. Implementation plan

Do these in order. Each phase is separately shippable.

### Phase 0: Lock in current behavior (small)
- Add characterization tests for each gap above so any change is intentional. There is already coverage in `tests/Feature/Subscription/` (`BasePlanTypePricingTest`, `BaseMaximumsTest`, `ActivateAppGateTest`) and `tests/Feature/Admin/PlansTest.php`. Extend those, covering `add-user` on an `app`-type plan, `domain_max = 0` on both services, and `{slug}.max = 0`.
- Query production data (or a staging copy) for plans where the old and new semantics would differ, e.g. `domain_max = 0` on an app plan, `{slug}.max = 0`. This sizes the migration in Phase 2.

### Phase 1: Fix billing and enforcement gaps (no data migration)
1. **Gap 4:** in `AppInstancePlanService`, return empty pricing and 0 totals when `Subscription::base()->type === 'package'`. This mirrors the guard `BasePlanService` already has. Add the corresponding admin validation that rejects prices on an app plan only when it is the sole plan selected by a packaged plan.
2. **Gap 3:** decide email billing at the org level. Recommended: bill it. Change `BasePlanService::pricingOptions()/stats()/totalPrice()` so that for `app` they return only the `email` entity instead of nothing.
3. **Gap 5:** add one accessor (e.g. `effectiveStoragePerUser($app_instance, $access_type)`) that reads from the base plan when the type is `package` and from the app plan otherwise. Use it in `AppStorageService::userStorage()` and `AdditionalStorageService`.
4. **Gap 2:** stop nulling `application.max` for `app` in `Plans::update()`. Add an `add-user` backstop for Pay per App: allow if the org has no ceiling, otherwise compare against it.
5. **Gap 6:** when an app plan is removed from a Pay per App plan's list, or the plan type changes, list the affected instances in the admin UI and block the save (or require an explicit "move these to plan X" choice). This replaces today's warning-only text.

### Phase 2: One meaning for limits (needs migration and UI change)
1. Add `app/Support/Plans/Limit.php` (or similar): `Limit::fromSetting($value)` returns `unlimited | none | cap(N)`, and `reached($count)` / `exceeded($count)`. Route `isMax`, `isMaxBroken`, `isMaxApps`, `isDomainMax` and `maxAllowedAdditionalStorage` through it so the semantics live in one place.
2. Migration: convert stored `0` to `null` where 0 currently means unlimited (`standard/basic/storage/email .max`, app-plan `domain_max`); leave `{slug}.max` and base `domain_max` at 0 (meaning none). Run it as a dry-run report first (Phase 0 query) and have a person review it.
3. Admin form: replace the bare numeric inputs with number + "Unlimited" toggle for every limit above. Show the type-specific "Required" markers from section 4.

### Phase 3: Guardrails and visibility
1. `config/plans.php` with the platform ceilings from section 4. `Plans::update()` and `Admin/Applications/Plans` (and `BulkEdit`) validate against it, and against the cross-field rules (`basic.storage ≤ standard.storage`, `{slug}.max ≤ application.max`, `max` is a multiple of `amount`).
2. Warn on save when a new limit is below the current usage of any subscriber (count via `Plan::subscribers()` / `AppPlan::subscribers()`). Grandfather, but surface it on the plan page.
3. On the subscriber pages (`Account/Subscription`), show usage against each limit and the next plan that would raise it. `SubscriptionService::plansWithHigherAppLimit()` already does this for apps.

### Out of scope until decided
- Whether Pay per App should also support an org-wide minimum or base fee (currently no base price at the org level).
- Metered or overage billing instead of hard caps.

## 6. Questions to resolve before Phase 1

1. Email on Pay per App: bill it (recommended) or hide the fields?
2. Packaged storage: confirm the package form's per-user storage should win over the app plan's.
3. Should Pay per App have an org-wide ceiling for users and apps at all, or is "sum of app-plan limits" enough? This plan assumes an optional ceiling.
4. Final numbers for the "Suggested default" column.
