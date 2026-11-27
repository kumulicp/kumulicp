# Suborganizations: plan to reach production

Status: draft, with product decisions recorded in section 7. Based on a code read of `claude/peaceful-hypatia-bvedls` at `c9725da`.
Nothing below was run. `vendor/` is not installed in this environment, so no tests were executed.
Items marked **(verify)** are suspected from reading the code and need a failing test before they are fixed.

## 1. What exists today

| Area | State |
|---|---|
| Feature flag | `sub-organizations` toggle gates `/settings/suborganizations` (`routes/web.php:308`) |
| Create / list / edit | `Account\Organization\Suborganization` (index, store, edit, update) |
| Delete | Out of scope (decided). Only deactivation ships. `destroy()` is a stub and should be removed (section 4) |
| Users | `suborg_users` table plus `SuborgUser`. Both account managers filter by it |
| Billing | `include_in_parent_invoice` setting, `JointSubscriptionService`, `SubscriptionUpdate` |
| Apps / domains | Parent sees suborg apps and domains (`allAppsIncludingChildren`, `Domains` controller) |
| App activation | Works, but the target suborg is picked last and most checks run against the parent (section 3) |
| Groups | Controllers and account managers are written but are not suborg-aware (section 2) |
| Tests | No feature test covers the `Suborganization` controller. Group tests only cover single-org cases, plus one cross-tenant DB test |

## 2. Groups (the known-broken area)

### 2.1 Root cause: the two account-manager drivers disagree about scope

- **DB driver** (`Database/Groups.php`): groups are scoped to `Organization::account()->id`, so scoping is per org.
  - A suborg user sees only the suborg's groups.
  - The parent admin sees only the parent's groups, although `Users::collect()` does return suborg users. The parent cannot see or manage suborg groups.
  - Group membership is limited to the group's org and its direct suborgs (`Database/Group.php:99`). A suborg group therefore cannot contain parent users.
- **LDAP driver** (`Ldap/Groups.php`, `Dn::create`): `Dn::create` replaces any suborg with its parent's slug (`Dn.php:44`).
  - Parent and every suborg therefore share one `ou=groups` tree.
  - This is a cross-tenant leak. Any suborg user can list, edit and delete the parent's and sibling suborgs' groups.
  - `Ldap\Group::updateMembers` and `updateManagers` accept any username and do no org check. The DB driver does check.
  - Group names collide across suborgs, because `GroupNameNotUsed` calls `find()` over the shared tree.

LDAP is the production driver (decided), so the LDAP fix is the priority and the DB driver is secondary.
A suborg also has no `o=` entry of its own in LDAP. `Accounts::create` calls `Dn::create`, which resolves to the parent's DN.
So there is currently nothing in the directory that says which org a group belongs to.
The fix (decided, 2.2) is to give each suborg its own `o=` path for groups.

### 2.2 Group scoping model (decided)

**Per-org groups. The parent admin can see and manage its suborgs' groups.**

- Suborg users only ever see their own org's groups.
- The parent admin gets a group list that includes suborg groups, labelled by org, and can create, edit and delete them.
- A parent user cannot belong to a suborg group (decided). The DB driver already enforces this. LDAP must too.
- A parent group may contain suborg users (decided). This is what the DB driver allows today (`belongsToGroupOrganization`).
- **LDAP layout (decided, option B, revised): suborg groups sit inside the parent's tree, beside the parent's `ou=groups`.** A suborg's groups live at
  `cn=<group>,ou=<category>,ou=groups,ou=<suborg-slug>,ou=suborgs,o=<parent-slug>,<base_dn>`. The parent's own groups stay where they are, at `cn=<group>,ou=<category>,ou=groups,o=<parent-slug>`.
  - **Why this layout:** the ACLs in `.github/ldap/acl.ldif` and the app bind accounts (for example Nextcloud binds as the parent's `cn=admin,o=<parent>`) both assume everything for a tenant sits under the parent's `o=`. Keeping suborg groups there means no ACL change and no new bind accounts.
  - **One `ou=groups` base per organization, holding only its own groups.** The suborgs' groups are not under the parent's `ou=groups`, so an app that can be given only a single group base and searches it as a subtree (Nextcloud takes several, Frappe/ERPNext's LDAP settings appear to take one) sees just that organization's groups. No search needs to exclude anything, and no app has to list category containers.
  - **Apps get their base from `LdapSupport::groupBase($organization)`.** Any app that reads the groups people make in the control panel must use it rather than build `ou=groups,<org>` by hand. A new app asks the helper and puts the result in its own config format.
  - **Apps that cannot be given a base at all** and only read a user's `memberOf` cannot be restricted by the layout. They still only see the groups that user is in, and a suborg group only contains that suborg's users.
  - Users stay where they are, in the parent's `ou=users`. Group `member` values point at those user DNs.
  - The group-DN builder (`Dn::group`) takes (org, category, name) and returns the right DN for a parent or a suborg. Do not change `Dn::create`, which users, apps and domains rely on.
  - The suborg's containers (`ou=suborgs`, `ou=<suborg-slug>`, `ou=groups`) are created lazily on first group, and should also be created when the suborg is created (section 4).
  - The parent's group list queries its own container plus each suborg's, labelled by org.
  - Still to check: `UpdateLDAPGroups`, `AddLdapGroups`, the Authentik group mapping, `Ldap\Models\User::groups()`, and the memberof overlay (`olcMemberOfRefint`) with the longer DNs. **(verify)** Also confirm with the ERPNext module's maintainer whether it reads `ou=groups`, and whether its group path is a single DN.
- **Existing groups.** Nobody is using suborg groups yet (decided), so no backfill or migration is needed. Anything already there stays with the parent.

### 2.3 Group work items

**Status (branch `claude/suborg-ldap-group-scoping`, not yet run):**
- **Done:** the group DN builder (`Dn::group`, `Dn::groupContainers`) and `LdapSupport::groupBase()`; Nextcloud's `LDAP_GROUP_BASE` now uses it, so an org's Nextcloud sees only its own groups (shared hubs keep the wide base); LDAP `Groups` and `Group` scoped to one organization (no searches over the whole `ou=groups` tree); suborg membership checked against `SuborgUser` for LDAP; `Account::groups()` passes its organization on both drivers; parent can list, edit and delete suborg groups (`Groups` controller resolves a group by organization and slug, `?organization=<id>` picks one when two suborgs share a name); DB driver groups take an organization; user-side group add/remove looks only in the user's organization and, for a suborg user, the parent's.
- **Not done yet:**
  - Existing Nextcloud instances keep their old group base until their environment is re-applied (upgrade or redeploy). Confirm how the image's `set-ldap.sh` handles `LDAP_GROUP_BASE`.
  - Creating the suborg's group containers when the suborg is created (they are created on first group).
  - The DB-driver fixes in item 5 (member removal, orphaned rows, slug on rename).
  - A test that the directory-reader and per-org admin ACLs still see suborg groups.

0. **Baseline: confirm the LDAP tests run (correction to an earlier draft).** CI already starts an OpenLDAP container with the memberof overlay and the per-org ACLs (`.github/ldap/*.ldif`) and runs the `AccountManagerLdap` suite in both `laravel.yml` and `laravel-frankenphp.yml`. An earlier version of this plan said CI had no LDAP. That was wrong.
   - `GroupsTest` calls `skipUnlessDriver('ldap', $driver)`, so only its `ldap` dataset row runs. The `db` row is skipped by design.
   - `GroupCrossTenantTest` forces the `db` driver and runs inside the same suite.
   - Local runs need PHP 8.4, `ext-ldap`, `ext-bcmath`, and a slapd with memberof and the ACLs. The cloud sandbox has PHP 8.3 and no Docker daemon, so the first job is to get a reproducible local runner (for example a setup script on the `kumulicp` environment) before writing code against LDAP.
   - Record the current pass/fail baseline of `AccountManagerLdap` before any change.
1. **Write failing tests first**, LDAP first, using the existing `account_manager_drivers` dataset:
   - create, edit, delete as a suborg user;
   - a suborg cannot see, edit or delete the parent's or a sibling's groups;
   - the parent can see, edit and delete suborg groups;
   - a parent user is rejected as a member or manager of a suborg group;
   - cross-org members are rejected on both drivers;
   - the same group name can exist in two suborgs;
   - existing parent groups still resolve after the DN change;
   - the suborg's group container is created on suborg creation and lazily on first group.
2. **Fix LDAP scoping.** Add a group-specific DN builder for the layout in 2.2.
   - Use the same builder in `Groups` (add, find, collect, count) and `Group` (updateMembers, updateManagers, updateCategory, rename).
   - Check `AddLdapGroups` (a job that sets the organization) and any other code that builds group DNs.
3. **Fix LDAP membership validation.** Mirror `belongsToGroupOrganization` in `Ldap\Group::updateMembers` and `updateManagers`. Org membership must come from `SuborgUser`, because parent and suborg users share one `ou=users` tree and the DN says nothing about the org.
   - The base `member` value on a new group (`Groups::add` sets it to the org DN) should point at the suborg's own `o=` entry, which now exists. Today it resolves to the parent's DN.
4. **Fix `GroupNameNotUsed`.** It must be scoped the same way as the groups themselves.
5. **DB driver correctness (verify, lower priority since LDAP is production):**
   - `Database\Group::updateManagers` detaches all members before re-attaching managers. This only works because `updateMembers` runs second. Make each method idempotent and independent.
   - `updateMembers` never removes users who were unselected.
   - `members()` and `managers()` read the cached `$this->group->members` relation. They may return stale data after an update in the same request.
   - `delete()` leaves orphaned `group_members` rows. Add a cascade or detach.
   - `updateName` changes `name` but not `slug`.
6. **Parent-level group view.** Update `Groups@index` to include suborg groups, and `Groups@edit` to resolve a group by (org, slug) rather than slug alone.
7. **App side-effects.** `Groups@store`, `update` and `destroy` dispatch `process_group_options` for `$organization->active_apps()`. Confirm that a suborg's group change targets only that suborg's Nextcloud instance and not the parent's. Check `activeAppsIncludingChildren` usage and `GroupFolderService`, which uses `$app_instance->organization`.
8. **User-side group assignment.** `Account\User\Groups@edit/add/remove` builds its list from `AccountManager::groups()->all()`. Check that a parent editing a suborg user sees that suborg's groups, not its own. Test it.
9. **Authorization.** Groups are gated only by `active` (a user in an active org). That is the documented, tested behaviour. Add an explicit gate on the group's owning org so a crafted slug cannot reach another org's group. This follows from 2.1.

## 3. App activation with suborganizations

### 3.1 How it works today

`/discover` -> `/discover/{app}` -> plan list -> `/discover/{app}/plans/{plan}/review` -> `POST .../activate`
(`Account\Discover`). Every step runs in the logged-in (parent) org's context. The target suborg is chosen last,
via an unlabeled `organization` select on the review page. It only appears when suborgs exist and defaults to the parent.
`ApplicationActivate` then runs for the chosen org.

### 3.2 Problems

1. **Checks run against the wrong org.** The target is only known at the end, but everything before it uses the viewer's org:
   - `show`, `plans` and `review` use `Subscription::base()` and `availablePlans($app, $organization)`.
   - The `activate-app` and `select-app-plan` gates use the viewer's base plan.
   - A suborg on a different plan is offered the parent's plans, and limits are checked against the parent.
   - The suborg's `status` is never checked. `activate-app` tests the parent's status.
2. **The limit logic is hard to reason about** (rule decided in 3.3). When suborgs exist, `activate-app` consults `JointSubscriptionService::isAppLimitReached`.
   - Its result is named `plans_with_higher_app_limit` and produces two different denial codes (`plan_limit_reached`, `limit_reached`).
   - The existing test only asserts "denied", without saying why.
   - `JointSubscriptionService` has no tests of its own, and has real bugs **(verify)**:
     - `allSubscriptions()` appends to `$this->subscriptions` on every call, so a repeated call duplicates entries and `totalPrice()` can double count.
     - `countEntity($entity)` ignores its argument.
     - `allSubscriptions()` excludes the parent and any suborg billed separately.
3. **Discover shows the parent's view only.** `installed` and `count` on `/discover` and `/discover/{app}` count only the viewer's own instances.
   - An app that is active only in a suborg looks "not activated".
   - `default_label` numbering counts the parent's instances only, so labels collide across orgs.
4. **Domains ignore the target org** (rule decided in 3.3).
   - `review` lists the parent's domains (`availableDomains`, `main_domains`).
   - `AppInstanceService::availableDomains` merges every suborg's subdomains regardless of the chosen target.
   - The duplicate-subdomain check and `Domain::addSubdomain` run before `$organization` is switched to the suborg. They use the parent's id.
   - `OrgSubdomain::find($value)` is not constrained to the target's family **(verify)**. A sibling suborg's domain could be accepted.
5. **Parent-app selection crosses orgs** (rule decided in 3.3). `availableParents` merges the parent's and every suborg's instances. A suborg app can be attached under a sibling's app. `OrgAppInstance` validates against the parent only.
6. **Payment check uses the wrong payer** (rule decided in 3.3). `Billing::hasDefaultPaymentMethod()` is checked for the viewer.
   - If the suborg is billed on the parent invoice (`include_in_parent_invoice`) this is right. `StripeGateway` rolls the update up to the parent.
   - If it is billed separately, the payer is the suborg. A parent admin currently sees the app activation screen for it and is checked against their own payment method.
7. **Target validation is weak.** `OrgSuborganization` uses `$value === $organization->id`. The request value is a numeric string, so selecting the parent itself can fail **(verify)**. It also accepts deactivated suborgs, while `review` only lists non-deactivated ones.
8. **After activation, nobody may be told.** `ApplicationActivate` notifies `$task->organization->admins()`.
   - A suborg created in `Suborganization@store` only has the parent's `primary_contact` associated. It has no users in `suborg_users` **(verify)**.
   - So the suborg's admin list may be empty and no one is notified.
   - `AppEdit` builds `registration_url` from the viewer's org slug, not the app's org (`Applications.php:146`) **(verify)**.
9. **No cost preview.** The review page doesn't say whose invoice the app lands on, or what it adds.
10. **Cleanup.** `Applications@reactivate` and `destroy` pass `Subscription::all()` (viewer's) next to `$app->organization`. It is harmless because `SubscriptionUpdate::run` rebuilds from the DB, but it is misleading.

### 3.3 Proposed flow

**Status (branch `claude/suborg-activation-context`, slice 1 of 3, not yet run):**
- **Done (server side):**
  - `AppActivationContext` works out, from the target org, who may activate (decisions 7 and 11), the limits (target's own plan) and who pays.
  - The `activate-app` and `select-app-plan` gates take an optional target org.
  - `Discover@activate` resolves the target first and checks the payer's payment method instead of the viewer's.
  - The review page only offers suborgs the user may activate for, and doesn't ask a suborg user whose parent pays for a payment method.
  - `OrgSuborganization` accepts string ids and refuses deactivated suborgs.
  - A per-suborg "Let this organization activate apps" switch on the suborg edit page, settable by the parent only.
  - `JointSubscriptionService` no longer decides limits, and no longer double counts on repeat calls.
  - `FastCache::retrieve('plans')` was keyed on the active org, so a suborg's plans could be read from the parent's cache entry on a taggable cache store. It now takes the org.
- **Not done:**
  - Slice 2: the org selector first (the app page and review take `?organization=`), per-target plans, labels and counts on the Discover pages, and domains/parent apps restricted to the target and its parent (decision 8).
  - Slice 3: notifications, the registration URL, the cost preview, and the optional family-wide ceiling plan setting.

1. **Choose the org first.**
   - Add an "Activating for" selector at the start (the app page).
   - List only the viewer's org and the non-deactivated suborgs that the viewer is allowed to activate for (rule below).
   - Show it only when more than one choice exists. Default to the parent.
   - Carry the choice through plans, review and activate as `?organization=<id>`.
2. **One activation context.** Build a single object from (app, target org) and use it in `show`, `plans`, `review`, `activate` and the gates. It holds:
   - the target's base plan and subscription;
   - the payer (parent if jointly billed, otherwise the suborg);
   - the allowed domains;
   - the allowed parent apps;
   - the limit result.

   The existing `sharedParentOnlyDomain` comment already describes this pattern: one source of truth so display and validation can't drift.
3. **Gates take the target.** `activate-app` and `select-app-plan` accept the target org. Authorization means: the target is the viewer's org or a non-deactivated child.
4. **Limits (decided): the target org's own plan decides.**
   - `include_in_parent_invoice` controls only who pays, not what is allowed. The roll-up already lives in `StripeGateway`.
   - `activate-app` asks one question: does the target org's plan allow another instance of this app? Use one denial code.
   - `JointSubscriptionService` goes back to being a pricing tool. Fix its bugs and unit-test it, but stop using it for limits.
   - Optional family-wide ceiling: a plan setting for the maximum instances of an app across the parent and all its suborgs.
     - It is unset by default, which means no ceiling.
     - It is read from the parent org's plan and counted with `allOrganizationIds()`.
     - It matters for free or zero-price plans, where creating suborgs would otherwise multiply the limits for nothing. Paid jointly billed suborgs each add their own base plan price to the parent invoice (`totalPrice()`).
     - Add a plan-edit field for it (`PlanEdit.vue` already has suborganization settings).
5. **Who can activate, and who pays (decided).**
   - A separately billed suborg can be activated into only by its own admins, using the suborg's own payment method. Parent admins do not see it in the selector, and `activate` rejects it server-side.
   - Check payment with `Billing::organization($payer)->hasDefaultPaymentMethod()` for the payer, not the viewer.
   - A jointly billed suborg is paid by the parent: the parent's payment method, and the parent's invoice.
   - **Per-suborg setting (decided): "Let this suborganization activate apps".** It is stored on each suborg with `updateSetting` (next to `include_in_parent_invoice`), off by default, and set by parent admins on the suborg edit page (`SuborganizationEdit.vue`). Suborg admins cannot change it.
     - Off: only parent admins can activate apps into that jointly billed suborg, as today.
     - On, and the suborg is jointly billed: suborg admins can activate apps themselves, billed to the parent, and the payment-method requirement is hidden for them. The review page and the `activate` check do not ask a suborg user for a payment method.
     - On, but the suborg is separately billed: the setting has no effect. That suborg always pays with its own method.
     - The suborg's own plan limits and the family ceiling still apply.
     - Assumption to confirm: hiding the requirement means not asking the suborg user for a card, but the server still checks that the parent has a default payment method when the plan is paid. If it doesn't, the suborg user sees a plain "ask your parent organization to add a payment method" message.
6. **Domains and parent apps (decided: domains only).**
   - A suborg app may use the suborg's own domains and the parent's domains. It may not use a sibling's domains.
   - It may not be attached under a parent's or a sibling's app. Parent-app candidates come from the target org only.
   - The explicit `parent_server_id` plan setting (shared hub apps) is a separate mechanism and is unchanged.
   - Enforce both in `review` (what is offered) and `activate` (what is accepted), through the activation context, so they can't drift. `OrgSubdomain::find` must be constrained to the allowed set.
7. **Review page content.** Show the target org, the plan, who pays and how much it adds, and the domain options for that org only.
8. **Discover counts.** Show per-target counts, plus a roll-up such as "also active in 2 suborganizations".
9. **Revalidate on `activate`.** Recompute the context server-side instead of trusting the earlier steps.
10. **After activation.**
   - Notify parent admins when the suborg has none.
   - Redirect to `/apps`, filtered to that org.
   - Use the app's org for the registration URL.

### 3.4 Organization filters: on every list page, and one universal filter

**Why.** A parent's lists mix its own data with its suborganizations'. Someone who works in one (sub)organization shouldn't have to pick it again on every page they open.

**Where lists mix organizations today** (from the code; none has an organization filter yet):
- **Users** (`Users@index`): `AccountManager::users()->collect()` returns the whole family. The two drivers also disagree elsewhere: the DB driver's `paginate()` lists only the organization's own users, the LDAP one lists the whole tree. The filter should fix that too.
- **Domains** (`Web\Domains@index`): merges the suborgs' domains.
- **Apps** (`Applications@index`, through `Organization::apps()`): includes the suborgs' apps and shows an organization name when `multiple_orgs`.
- **Groups**: the list now has an organization column for a parent (the suborg-groups work) but no filter.
- **Dashboard** (`HomeController`, `OrganizationService::count*`) and **Subscription** (a section per organization, plus totals).
- **To audit first:** email addresses and mailboxes, tickets, notifications, backups, and any other list. Grep for `suborganizations`, `allOrganizationIds` and `*IncludingChildren`, and list every page the audit finds in the PR.

**Design**
1. **One service decides what is in scope.** An `OrganizationFilter` service, session-backed, that every list controller asks instead of reading request parameters itself:
   - `options(User)`: the user's own organization plus its non-deactivated suborganizations. Empty (or just one) for a user whose organization has none.
   - `selected()`: the universal filter, or null for "all organizations".
   - `ids(Request)`: the organization ids in scope. With a universal filter set, that one. Otherwise the page's `?organization=<id>`, if it is valid. Otherwise the whole family.
   - Any id outside the user's family is refused, so a crafted value can't widen a list.
2. **Page filters.** Every list above gets `?organization=<id>` (shareable, back-button friendly) and a small control above the list. The control is not shown when a universal filter is set, and the page parameter is then ignored, so the two never disagree.
3. **Universal filter.** `POST /organization-filter` with an organization id, or empty for all. Stored in the session and cleared on logout. `HandleInertiaRequests` shares `org_filter: { selected, options }` on every page.
4. **Top bar button** (next to the profile dropdown, `AppNavbarActions.vue`). The label is the selected organization's name, or "All organizations". It opens a modal that looks and works like the new organization modal on `/discover/<app>` (the radio list in `DiscoverApp.vue`), with an extra "All organizations" option.
   - Extract that radio list into one shared component (`OrganizationPicker.vue`: options with `id`, `name`, `disabled`, `note`, and `v-model`) so the Discover modal, the top bar modal and the page filters stay the same.
   - The button, the modal and the page filters appear only when the user's organization has suborganizations. For everyone else nothing changes.
5. **Filtering at the source.** Lists filter in the query (`organization_id` in the ids) rather than in the page. For users, add an optional organization to the account managers' `collect()` and `paginate()` on both drivers, using the user's organization (`SuborgUser` on LDAP, `organization_id` on the DB driver). The dashboard counts and the subscription page take the same ids; the subscription page keeps showing the parent's invoice total, with a note when it is filtered.
6. **Creating things follows the filter.** With a universal filter set, create forms default to that organization (add user, add domain, create group, activate app). On `/discover/<app>` the organization modal is skipped when the selected organization can take the app, and shows why when it can't. With "All organizations" everything behaves as today.
7. **A view filter, not a permission.** A link to something outside the filter still opens it. Authorization is unchanged. Optionally show a notice: "This belongs to X, you're viewing Y".

**Tests**
- `OrganizationFilter`: an unrelated organization or a sibling is refused, a deactivated suborg is not offered, the selection is kept in the session and cleared on logout, the universal filter wins over the page parameter, and a user whose organization has no suborganizations gets no options.
- Shared props: `org_filter` has options only when there are suborgs.
- Each list page: filtered by the page parameter, filtered by the universal filter, the universal filter overrides the page parameter, and a user with no suborganizations sees no change. Users on both drivers return the same rows.
- Creation defaults follow the selected organization.

## 4. Rest of the suborganization flow

### Blockers
- **Creation does not provision anything.** `Suborganization@store` only saves the row. The `// Add domain` comment has no code under it.
  - Create the account-manager org with `AccountManager::accounts()->create`. The LDAP `Accounts::create` makes the `o=` entry, its OUs and the admin entry.
  - Create the base domain.
  - Dispatch whatever the normal registration path dispatches in `Pub\Register` and the setup flow.
  - Compare the three paths and extract the shared logic into one action, instead of the current copy.
  - **Status (branch `claude/suborg-creation-provisioning`, not yet run):** the base domain is not a stored row (`AppInstance::base_domain()` builds it from the slug), so there is nothing to create for it. Registration's other steps (user, admin access, events) don't apply to a suborg. `store` now calls `AccountManager::accounts()->create()`, which for LDAP only adds the suborg's group containers because everything else already exists under the parent, and queues a `SubscriptionUpdate` for the suborg so its plan reaches the parent invoice when it is billed jointly. A suborg still has no admins of its own: `primary_contact` is the parent's, and who `notifyAdmins` reaches for a suborg on LDAP needs checking. **(verify)**
  - **Found while doing this: deleting a suborg could delete its parent's whole LDAP tree.** `Dn::create()` resolves a suborg to its parent's `o=`, and `Account::destroy()` deleted that entry recursively. The admin "delete organization" action and the scheduled deletion (`DeleteOrganization::complete()`) can both reach a deactivated suborg. Fixed: for a suborg, `destroy()` now removes only its group containers and its own users' entries (and `SuborgUser` rows). Tests in `SuborgGroupsTest`. The same collapse affects any other code that treats `Dn::create($suborg)` as the suborg's own entry, so grep for it before shipping.
- **No delete (decided). Suborgs are deactivated only.**
  - Remove the `destroy()` stub and the `DELETE /settings/suborganizations/{organization}` route, and any UI for it. The `// not deleted yet` message and its translations go with it.
  - The `delete-organization` gate then has no caller for suborgs. Leave it, or remove it if nothing else uses it.
  - Deactivation lives in `Subscription@cancel` and `resubscribe`. Both need work first (next item).
- **Deactivation and reactivation are not safe for suborgs (verify).**
  - **Status:** `cancel` now authorizes with the `unsubscribe` gate, and `resubscribe` takes the `{organization}` from the route and authorizes with the `resubscribe` gate. Both build the subscription for the target org. Tests: `tests/Feature/Subscription/SuborgDeactivationTest.php`. Still open: cancel uses the viewer's `Billing` facade for `isBillable()` and `periodEnds()`, and the apps of a cancelled parent's suborgs are not marked `deactivating`.
  - `Subscription@cancel(Organization $organization)` has no `Gate::authorize('unsubscribe', ...)`. The gate exists and is used only to decide whether to show the button. If nothing else guards the route, any authenticated user can cancel any organization by id. Write a test for this first.
  - `Subscription@resubscribe()` takes no organization argument and uses `auth()->user()->organization`, although the route is `/subscription/{organization}/resubscribe`. Resubscribing a suborg from the parent resubscribes the parent and all its children instead.
  - `cancel` passes the viewer's `SubscriptionFacade::all()` to `SubscriptionUpdate` for the suborg. `run()` rebuilds from the DB, so it works but is misleading.
  - Define what deactivating a suborg does: apps to `deactivating`, billing roll-up on the parent invoice, users unable to log in (`active` gate), and what the parent sees afterwards. Then test deactivate and reactivate on a suborg alone, and cascade from the parent.
  - Deactivated suborgs must be excluded from the activation target list (section 3) and from `add-suborganization` style checks.
- **Enforce the plan flag on create.** *(Done: the `add-suborganization` gate now checks the plan setting, with tests.)* Nothing checked `plan->setting('suborganizations.enabled')` on store. `view-suborganizations` checks it, `add-suborganization` does not. Add it. There is no cap on the number of suborgs (decided), so no max-count setting.
- **The suborg inherits the parent's `plan_id`.** The create form offers `org_types`, but `type` does not drive plan selection. Decide the intended behaviour and test it.

### Correctness
- **Update field mismatch.** `edit()` returns `email` and `phone_number`, but `update()` validates `org_email` and `org_phone_number`. Check what `SuborganizationEdit.vue` actually sends.
- **Contact fields.** `user_*` is `required_if:include_in_parent_invoice,false`, but `update()` writes it unconditionally. A parent-billed suborg ends up with `null` contacts that were copied from the parent at creation.
- **Update calls the driver.** It calls `AccountManager::account($organization)->update(...)`, which is a no-op `update($data) {}` on both drivers. LDAP org attributes then drift from the DB.
- **Failure cleanup in `store`.** *(Done for now: the catch removes the suborg's own directory entries through the now-safe `destroy()`, then the row. It is not transactional, and the queued update task is not cancelled.)*
- **Billing toggle.** Switching `include_in_parent_invoice` dispatches `SubscriptionUpdate` for the parent only. Confirm the Stripe-side effect on the suborg's own subscription, and that `JointSubscriptionService::countEntity()` is correct (it takes `$entity` but calls `countEntity()` with no argument).
- **Cross-org checks** for suborgs in the gates (`view-user`, `edit-user`, `delete-user`, `activate-app`, etc.). The parent-to-child rule is repeated by hand in a dozen places. Move it to one `Organization::isSelfOrChildOf()` helper so it is tested once.

### Product and UI
- Suborg users should not see the Suborganizations menu. `view-suborganizations` already denies them.
- Show deactivated suborgs clearly in the list (status badge, no edit, reactivate action for the parent).
- Decide whether suborg admins can log in to a parent-scoped view or only to their own.

## 5. Tests required before release

- `tests/Feature/Organization/SuborganizationTest.php`: index, store (valid, invalid, duplicate subdomain, plan flag off), edit, update, billing toggle, deactivate, reactivate, and the removed delete route returning 404/405.
- Authorization matrix: parent admin, suborg admin, sibling suborg admin, unrelated org user, against each action.
- Groups: the cases in 2.3.1, run on `ldap` first and then `db`. They must actually run in CI (2.3.0).
- App activation (`tests/Feature/Applications/SuborgActivationTest.php`), driving the real `Discover` routes:
  - activate into the parent and into a suborg;
  - the limit is evaluated on the target org's own plan, for jointly billed and separately billed suborgs;
  - the optional family-wide ceiling blocks activation when it is set and is ignored when unset;
  - a parent admin cannot activate into a separately billed suborg, and a suborg admin can, using the suborg's payment method;
  - a deactivated suborg, a sibling org and a non-family org are rejected;
  - the parent's domain is accepted for a suborg app, and a sibling's domain is rejected;
  - a parent app from the parent or a sibling is rejected as a parent-app candidate;
  - the payment-method gate checks the right payer;
  - the string-id case for `OrgSuborganization`;
  - admins are notified when the suborg has no admins.
- Unit tests for `JointSubscriptionService` (repeated calls, separate billing, `countEntity`).
- One end-to-end test: create a suborg, add a user, create a group, add the user to it, activate an app, check the invoice, deactivate, reactivate.

## 6. Suggested order

1. Establish a local test baseline (2.3.0). CI already runs LDAP. Group scoping, delete scope and driver are already decided (section 7).
2. Add failing tests (section 5), LDAP groups first, plus the cancel/resubscribe authorization test.
3. Fix LDAP group scoping and membership (2.3.2 to 2.3.4), backfill existing groups, then the parent view (2.3.6 to 2.3.9). DB driver fixes (2.3.5) after that.
4. Fix the creation provisioning and plan-flag enforcement.
5. Rework app activation (section 3): fix `JointSubscriptionService`, add the activation context, the per-plan limit and the optional family ceiling, then the gates, the org selector first, and the UI and notifications.
6. Organization filters (3.4): the audit of lists, the `OrganizationFilter` service, the shared picker component and the top bar button, then page by page (users, domains, apps, groups, dashboard, subscription).
7. Fix the update and billing issues.
8. Remove the delete stub and route; harden suborg deactivation and reactivation.
9. Staging soak with the toggle on for a single real tenant, then enable it by default.

## 7. Decisions and open questions

### Decided
1. The parent admin can manage (not just see) suborg groups.
2. A parent user cannot belong to a suborg group.
3. Suborgs can be deactivated but not deleted.
4. LDAP is the production account-manager driver.
5. There is no cap on the number of suborgs per plan.
6. App limits come from the target suborg's own plan. `include_in_parent_invoice` only decides who pays. An optional family-wide ceiling plan setting covers free plans.
7. A separately billed suborg can only be activated into by its own admins, with the suborg's payment method.
8. A suborg app may use the parent's domains (domains only). It may not use a parent or sibling app as its parent app. Sibling domains are not allowed (assumed from "domain only").
9. Suborg groups live inside the parent's LDAP tree, under `ou=groups,ou=<suborg-slug>,ou=suborgs,o=<parent>`, beside the parent's own `ou=groups` (option B, revised). A separate `o=` per suborg was rejected because it conflicts with the ACLs and Nextcloud's bind account.
10. A parent group may contain suborg users.

11. For a jointly billed suborg, suborg admins can activate apps only when the parent turns on a per-suborg "Let this suborganization activate apps" setting (off by default). When it is on, the payment requirement is hidden from the suborg user (3.3).
12. Nobody uses suborg groups in production yet, so no backfill of existing groups is needed.
13. Sibling domains are not allowed for suborg apps. Only the suborg's own and the parent's domains are.

14. Every list page gets an organization filter, and one universal filter in the top bar applies to all pages. When it is set, the page filters are hidden. Its modal works like the organization modal on `/discover/<app>` (3.4).

15. Each new suborg starts with the activation setting off. The parent does not get a default for new suborgs in the first release.
16. The universal organization filter lasts for the session and is not remembered per user.
17. The subscription and billing pages follow the universal filter by showing the selected organization's section, and keep the parent's invoice total visible.

### Still open
None at the moment.
