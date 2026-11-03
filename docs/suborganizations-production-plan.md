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

### 2.2 Group scoping model (decided)

**Per-org groups. The parent admin can see and manage its suborgs' groups.**

- Suborg users only ever see their own org's groups.
- The parent admin gets a group list that includes suborg groups, labelled by org, and can create, edit and delete them.
- A parent user cannot belong to a suborg group (decided). The DB driver already enforces this. LDAP must too.
- Assumption to confirm: a parent group may still contain suborg users, which is what the DB driver allows today (`belongsToGroupOrganization`).
- In LDAP, store each suborg's groups under their own subtree inside the parent's, for example `ou=<category>,ou=<suborg-slug>,ou=groups,o=<parent>`, built by a group-specific DN helper.
  - The parent's own groups stay where they are.
  - Subtree searches must be restricted to one level. `LdapGroup::in(...)` is a subtree search by default, so a plain lookup of `ou=groups` would otherwise return suborg groups as well.
  - Existing groups that suborg users created sit in the parent's tree and cannot be attributed to an org. Decide how to backfill them (assign to the parent, or tag by creator) before release.

### 2.3 Group work items

0. **Make the LDAP tests run.** `skipUnlessDriver('ldap')` skips every LDAP case unless the driver is configured. `docker-compose.yml.sample` has an `openldap` service, but I found no CI workflow that starts one.
   - Add an LDAP service to CI (or a documented local command) before relying on any LDAP result.
   - Without it, the production driver is the untested one.
1. **Write failing tests first**, LDAP first, using the existing `account_manager_drivers` dataset:
   - create, edit, delete as a suborg user;
   - a suborg cannot see, edit or delete the parent's or a sibling's groups;
   - the parent can see, edit and delete suborg groups;
   - a parent user is rejected as a member or manager of a suborg group;
   - cross-org members are rejected on both drivers;
   - the same group name can exist in two suborgs;
   - existing parent groups still resolve after the DN change.
2. **Fix LDAP scoping.** Add a group-specific DN builder (see 2.2). `Dn::create` is shared with users, apps and domains, so do not change it globally. Restrict group searches to one level.
   - Use the same builder in `Groups` (add, find, collect, count) and `Group` (updateMembers, updateManagers, updateCategory, rename).
   - Check `AddLdapGroups` (a job that sets the organization) and any other code that builds group DNs.
3. **Fix LDAP membership validation.** Mirror `belongsToGroupOrganization` in `Ldap\Group::updateMembers` and `updateManagers`. Org membership must come from `SuborgUser`, because parent and suborg users share one `ou=users` tree and the DN says nothing about the org.
   - Also keep the base `member` value on a new group (`Groups::add` sets it to the org DN) valid for a suborg group. It currently resolves to the parent's DN.
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
2. **The limit logic is hard to reason about.** When suborgs exist, `activate-app` consults `JointSubscriptionService::isAppLimitReached`.
   - Its result is named `plans_with_higher_app_limit` and produces two different denial codes (`plan_limit_reached`, `limit_reached`).
   - The existing test only asserts "denied", without saying why.
   - `JointSubscriptionService` has no tests of its own, and has real bugs **(verify)**:
     - `allSubscriptions()` appends to `$this->subscriptions` on every call, so a repeated call duplicates entries and `totalPrice()` can double count.
     - `countEntity($entity)` ignores its argument.
     - `allSubscriptions()` excludes the parent and any suborg billed separately.
3. **Discover shows the parent's view only.** `installed` and `count` on `/discover` and `/discover/{app}` count only the viewer's own instances.
   - An app that is active only in a suborg looks "not activated".
   - `default_label` numbering counts the parent's instances only, so labels collide across orgs.
4. **Domains ignore the target org.**
   - `review` lists the parent's domains (`availableDomains`, `main_domains`).
   - `AppInstanceService::availableDomains` merges every suborg's subdomains regardless of the chosen target.
   - The duplicate-subdomain check and `Domain::addSubdomain` run before `$organization` is switched to the suborg. They use the parent's id.
   - `OrgSubdomain::find($value)` is not constrained to the target's family **(verify)**. A sibling suborg's domain could be accepted.
5. **Parent-app selection crosses orgs.** `availableParents` merges the parent's and every suborg's instances. A suborg app can be attached under a sibling's app. `OrgAppInstance` validates against the parent only.
6. **Payment check uses the wrong payer.** `Billing::hasDefaultPaymentMethod()` is checked for the viewer.
   - If the suborg is billed on the parent invoice (`include_in_parent_invoice`) this is right. `StripeGateway` rolls the update up to the parent.
   - If it is billed separately, the payer is the suborg, whose payment method a parent admin can't manage.
7. **Target validation is weak.** `OrgSuborganization` uses `$value === $organization->id`. The request value is a numeric string, so selecting the parent itself can fail **(verify)**. It also accepts deactivated suborgs, while `review` only lists non-deactivated ones.
8. **After activation, nobody may be told.** `ApplicationActivate` notifies `$task->organization->admins()`.
   - A suborg created in `Suborganization@store` only has the parent's `primary_contact` associated. It has no users in `suborg_users` **(verify)**.
   - So the suborg's admin list may be empty and no one is notified.
   - `AppEdit` builds `registration_url` from the viewer's org slug, not the app's org (`Applications.php:146`) **(verify)**.
9. **No cost preview.** The review page doesn't say whose invoice the app lands on, or what it adds.
10. **Cleanup.** `Applications@reactivate` and `destroy` pass `Subscription::all()` (viewer's) next to `$app->organization`. It is harmless because `SubscriptionUpdate::run` rebuilds from the DB, but it is misleading.

### 3.3 Proposed flow

1. **Choose the org first.**
   - Add an "Activating for" selector at the start (the app page).
   - Show it only when the viewer's org has non-deactivated suborgs. Default to the parent.
   - Carry the choice through plans, review and activate as `?organization=<id>`.
2. **One activation context.** Build a single object from (app, target org) and use it in `show`, `plans`, `review`, `activate` and the gates. It holds:
   - the target's base plan and subscription;
   - the payer (parent if jointly billed, otherwise the suborg);
   - the allowed domains;
   - the allowed parent apps;
   - the limit result.

   The existing `sharedParentOnlyDomain` comment already describes this pattern: one source of truth so display and validation can't drift.
3. **Gates take the target.** `activate-app` and `select-app-plan` accept the target org. Authorization means: the target is the viewer's org or a non-deactivated child.
4. **Write the limit rules down, then implement them.** For example: evaluate the target's own plan, and when jointly billed also the parent's aggregate. Fix `JointSubscriptionService` and unit-test it.
5. **Review page content.** Show the target org, the plan, who pays and how much it adds, and the domain options for that org only.
6. **Discover counts.** Show per-target counts, plus a roll-up such as "also active in 2 suborganizations".
7. **Revalidate on `activate`.** Recompute the context server-side instead of trusting the earlier steps.
8. **After activation.**
   - Notify parent admins when the suborg has none.
   - Redirect to `/apps`, filtered to that org.
   - Use the app's org for the registration URL.

## 4. Rest of the suborganization flow

### Blockers
- **Creation does not provision anything.** `Suborganization@store` only saves the row. The `// Add domain` comment has no code under it.
  - Create the account-manager org with `AccountManager::accounts()->create`. The LDAP `Accounts::create` makes the `o=` entry, its OUs and the admin entry.
  - Create the base domain.
  - Dispatch whatever the normal registration path dispatches in `Pub\Register` and the setup flow.
  - Compare the three paths and extract the shared logic into one action, instead of the current copy.
- **No delete (decided). Suborgs are deactivated only.**
  - Remove the `destroy()` stub and the `DELETE /settings/suborganizations/{organization}` route, and any UI for it. The `// not deleted yet` message and its translations go with it.
  - The `delete-organization` gate then has no caller for suborgs. Leave it, or remove it if nothing else uses it.
  - Deactivation lives in `Subscription@cancel` and `resubscribe`. Both need work first (next item).
- **Deactivation and reactivation are not safe for suborgs (verify).**
  - `Subscription@cancel(Organization $organization)` has no `Gate::authorize('unsubscribe', ...)`. The gate exists and is used only to decide whether to show the button. If nothing else guards the route, any authenticated user can cancel any organization by id. Write a test for this first.
  - `Subscription@resubscribe()` takes no organization argument and uses `auth()->user()->organization`, although the route is `/subscription/{organization}/resubscribe`. Resubscribing a suborg from the parent resubscribes the parent and all its children instead.
  - `cancel` passes the viewer's `SubscriptionFacade::all()` to `SubscriptionUpdate` for the suborg. `run()` rebuilds from the DB, so it works but is misleading.
  - Define what deactivating a suborg does: apps to `deactivating`, billing roll-up on the parent invoice, users unable to log in (`active` gate), and what the parent sees afterwards. Then test deactivate and reactivate on a suborg alone, and cascade from the parent.
  - Deactivated suborgs must be excluded from the activation target list (section 3) and from `add-suborganization` style checks.
- **Enforce the plan flag on create.** Nothing checks `plan->setting('suborganizations.enabled')` on store. `view-suborganizations` checks it, `add-suborganization` does not. Add it. There is no cap on the number of suborgs (decided), so no max-count setting.
- **The suborg inherits the parent's `plan_id`.** The create form offers `org_types`, but `type` does not drive plan selection. Decide the intended behaviour and test it.

### Correctness
- **Update field mismatch.** `edit()` returns `email` and `phone_number`, but `update()` validates `org_email` and `org_phone_number`. Check what `SuborganizationEdit.vue` actually sends.
- **Contact fields.** `user_*` is `required_if:include_in_parent_invoice,false`, but `update()` writes it unconditionally. A parent-billed suborg ends up with `null` contacts that were copied from the parent at creation.
- **Update calls the driver.** It calls `AccountManager::account($organization)->update(...)`, which is a no-op `update($data) {}` on both drivers. LDAP org attributes then drift from the DB.
- **Failure cleanup in `store`.** It deletes the org row but nothing else. Once provisioning is added, make the whole thing transactional or use compensating steps.
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
  - the limit is evaluated on the target org, for jointly billed and separately billed suborgs;
  - a deactivated suborg, a sibling org and a non-family org are rejected;
  - a domain from another org and a parent app from another org are rejected;
  - the payment-method gate checks the right payer;
  - the string-id case for `OrgSuborganization`;
  - admins are notified when the suborg has no admins.
- Unit tests for `JointSubscriptionService` (repeated calls, separate billing, `countEntity`).
- One end-to-end test: create a suborg, add a user, create a group, add the user to it, activate an app, check the invoice, deactivate, reactivate.

## 6. Suggested order

1. Get an LDAP service running in CI (2.3.0). Group scoping, delete scope and driver are already decided (section 7).
2. Add failing tests (section 5), LDAP groups first, plus the cancel/resubscribe authorization test.
3. Fix LDAP group scoping and membership (2.3.2 to 2.3.4), backfill existing groups, then the parent view (2.3.6 to 2.3.9). DB driver fixes (2.3.5) after that.
4. Fix the creation provisioning and plan-flag enforcement.
5. Rework app activation (section 3): fix `JointSubscriptionService`, add the activation context and gates, move the org selector first, then update the UI and notifications.
6. Fix the update and billing issues.
7. Remove the delete stub and route; harden suborg deactivation and reactivation.
8. Staging soak with the toggle on for a single real tenant, then enable it by default.

## 7. Decisions and open questions

### Decided
1. The parent admin can manage (not just see) suborg groups.
2. A parent user cannot belong to a suborg group.
3. Suborgs can be deactivated but not deleted.
4. LDAP is the production account-manager driver.
5. There is no cap on the number of suborgs per plan.

### Still open
6. Activation limits: should a suborg be limited by its own plan, the parent's plan, or both when jointly billed?
7. When a suborg is billed separately, can a parent admin activate an app in it? If yes, who is asked for the payment method?
8. May a suborg app use a parent-owned domain or parent app? May it use a sibling's?
9. Existing groups that suborg users already created sit in the parent's LDAP tree. Should they move to the parent, or be matched to the creator's suborg? (Is anyone using suborg groups in production yet?)
10. May a parent group contain suborg users? (Assumed yes, as the DB driver allows today.)
