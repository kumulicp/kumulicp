# Suborganizations: plan to reach production

Status: draft, based on a code read of `claude/peaceful-hypatia-bvedls` at `c9725da`.
Nothing below was run. `vendor/` is not installed in this environment, so no tests were executed.
Items marked **(verify)** are suspected from reading the code and need a failing test before they are fixed.

## 1. What exists today

| Area | State |
|---|---|
| Feature flag | `sub-organizations` toggle gates `/settings/suborganizations` (`routes/web.php:308`) |
| Create / list / edit | `Account\Organization\Suborganization` (index, store, edit, update) |
| Delete | Stub. `destroy()` has no `return` and does nothing |
| Users | `suborg_users` table plus `SuborgUser`. Both account managers filter by it |
| Billing | `include_in_parent_invoice` setting, `JointSubscriptionService`, `SubscriptionUpdate` |
| Apps / domains | Parent sees suborg apps and domains (`allAppsIncludingChildren`, `Domains` controller) |
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

The first task is a product decision, not code (see 2.2).

### 2.2 Decision needed: group scoping model

Recommended option: **per-org groups, and the parent can see and manage its suborgs' groups**, which matches how users, apps and domains already behave.

- Suborg users only ever see their own org's groups.
- The parent admin gets a group list that includes suborg groups, labelled by org.
- A group may only contain users from its own org. Whether a parent user may join a suborg group is a rule to confirm.
- LDAP gets a per-suborg groups OU (for example `ou=groups,ou=<suborg-slug>,o=<parent>`), or a stored owner attribute.

Alternative: one shared group namespace for the whole tenant, with suborgs as members. It is simpler for LDAP, but it breaks the DB driver's existing scoping and is a bigger behaviour change.

### 2.3 Group work items

1. **Write failing tests first** for both drivers, parameterised with the existing `account_manager_drivers` dataset:
   - create, edit, delete as a suborg user;
   - a suborg cannot see, edit or delete the parent's or a sibling's groups;
   - the parent can see suborg groups (per 2.2);
   - cross-org members are rejected on both drivers;
   - the same group name can exist in two suborgs.
2. **Fix LDAP scoping.** Stop collapsing to the parent in group DNs, or add an org-owner attribute. Reconcile this with `Dn::create`, which is also used for users, apps and domains, so do not change it globally. Add a group-specific DN builder.
3. **Fix LDAP membership validation.** Mirror `belongsToGroupOrganization` in `Ldap\Group::updateMembers` and `updateManagers`.
4. **Fix `GroupNameNotUsed`.** It must be scoped the same way as the groups themselves.
5. **DB driver correctness (verify):**
   - `Database\Group::updateManagers` detaches all members before re-attaching managers. This only works because `updateMembers` runs second. Make each method idempotent and independent.
   - `updateMembers` never removes users who were unselected.
   - `members()` and `managers()` read the cached `$this->group->members` relation. They may return stale data after an update in the same request.
   - `delete()` leaves orphaned `group_members` rows. Add a cascade or detach.
   - `updateName` changes `name` but not `slug`.
6. **Parent-level group view.** Update `Groups@index` to include suborg groups, and `Groups@edit` to resolve a group by (org, slug) rather than slug alone.
7. **App side-effects.** `Groups@store`, `update` and `destroy` dispatch `process_group_options` for `$organization->active_apps()`. Confirm that a suborg's group change targets only that suborg's Nextcloud instance and not the parent's. Check `activeAppsIncludingChildren` usage and `GroupFolderService`, which uses `$app_instance->organization`.
8. **User-side group assignment.** `Account\User\Groups@edit/add/remove` builds its list from `AccountManager::groups()->all()`. Check that a parent editing a suborg user sees that suborg's groups, not its own. Test it.
9. **Authorization.** Groups are gated only by `active` (a user in an active org). That is the documented, tested behaviour. Add an explicit gate on the group's owning org so a crafted slug cannot reach another org's group. This follows from 2.1.

## 3. Rest of the suborganization flow

### Blockers
- **Creation does not provision anything.** `Suborganization@store` only saves the row. The `// Add domain` comment has no code under it.
  - Create the account-manager org with `AccountManager::accounts()->create`. The LDAP `Accounts::create` makes the `o=` entry, its OUs and the admin entry.
  - Create the base domain.
  - Dispatch whatever the normal registration path dispatches in `Pub\Register` and the setup flow.
  - Compare the three paths and extract the shared logic into one action, instead of the current copy.
- **Delete is a stub.**
  - `destroy()` is missing `return`. It also has no gate. `delete-organization` exists in the gates, but no code uses it for suborgs.
  - Implement deactivate and then delete, covering apps, domains, `suborg_users`, the LDAP subtree, subscription items and the invoice effect.
  - Alternatively, remove the route and UI button and ship without delete.
- **No limit enforcement on create.** Nothing checks `plan->setting('suborganizations.enabled')` on store. `view-suborganizations` checks it, `add-suborganization` does not. Add it, plus a max-count setting if one is wanted.
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
- Translate `Suborganizations cannot be deleted yet! :(` and its siblings (`en` and `es`).
- Decide whether suborg admins can log in to a parent-scoped view or only to their own.

## 4. Tests required before release

- `tests/Feature/Organization/SuborganizationTest.php`: index, store (valid, invalid, duplicate subdomain, limit reached), edit, update, billing toggle, destroy.
- Authorization matrix: parent admin, suborg admin, sibling suborg admin, unrelated org user, against each action.
- Groups: the cases in 2.3.1, run on both `db` and `ldap` (the LDAP cases are skipped in CI without an LDAP server; document how to run them).
- One end-to-end test: create a suborg, add a user, create a group, add the user to it, activate an app, check the invoice, deactivate.

## 5. Suggested order

1. Decide the group model (2.2) and the delete scope (3). These unblock everything else.
2. Add failing tests (section 4), starting with groups.
3. Fix group scoping and membership (2.3.2 to 2.3.5), then the parent view (2.3.6 to 2.3.9).
4. Fix the creation provisioning and limit enforcement.
5. Fix the update and billing issues.
6. Implement delete (or cut it).
7. Staging soak with the toggle on for a single real tenant, then enable it by default.

## 6. Open questions

1. Should the parent admin manage suborg groups, or only see them?
2. Can a parent user belong to a suborg group?
3. Is delete in scope for the first production release?
4. Is the DB driver or LDAP the production driver for the first tenants? That decides which group fix is urgent.
5. Is there a cap on how many suborgs a plan allows?
