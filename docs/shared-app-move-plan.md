# Plan: moving a shared app to another server

Status: proposed. Nothing here is built. It extends `docs/server-transitions-plan.md`
(the database, SSO and web moves, all built) to **shared apps**: the hub instances in the
`shared` organization that other organizations' apps run on (Nextcloud, WordPress,
ERPNext, B1Church and so on). Read that doc's "Rules that apply to every move" first; they all
hold here.

## Where we are

A shared app is an `AppInstance` in the organization with `type = 'shared'`
(`AppInstance::isSharedApp()`). Other organizations subscribe to a plan whose
`server_type = shared` and `shared_app_id` points at it; each subscription becomes a **child**
instance (`parent_id` = the hub). A child has no release of its own: every add, update and
delete is applied by rebuilding the hub's release (`AppInstance::sharedPlanParent()`,
`KubernetesWebInterface::updateSharedParent()`, `ApplicationService::runJob()`).

What already works for a shared app, because it is an ordinary instance:

| Move | Today for a shared app |
|---|---|
| Database (`ApplicationDatabaseMigrate`) | Allowed by `MoveBlockers` (only a web move checks for children). Not reachable from the shared app pages. For a hub without multisite there is nothing of the children's to move (see "Two kinds of shared app"). |
| SSO (`ApplicationSsoMove`) | Allowed for the hub. Children (`IS_CHILD`) are refused, which is right: they sign in through the hub. Not reachable from the shared app pages. |
| Web (`ApplicationWebMove`) | **Blocked** by `MoveBlockers::HAS_CHILDREN` as soon as one child is not deactivated, which is every hub that is in use. |
| Pointer edit (repair) | Reachable on `/admin/organizations/{shared org}/apps/{hub}/edit`. |

The pages are already there: the move tab is `/admin/organizations/{org}/apps/{app}/move`, and
`SharedAppContext::fromRequest()` already wraps any page whose route has an `app` parameter
that is a shared app in the shared-app layout. But the shared-app tabs
(`SharedAppContext::payload()['urls']`) have no link to it, so there is no way to get there
from `/admin/service/shared-apps/{id}`.

So "move shared apps" is mostly **web move**: lift the children block safely, and carry the
children's side of the move (DNS, and for multisite apps their database and sign-in pointers)
with the hub. Database and SSO moves need the buttons surfaced and a check for what they do to
children.

## Two kinds of shared app

Whether a child has anything of its own is decided by `AppInstance::usesMultisitePermissions()`
(the profile's `multisite` compatibility), which is also what `usesOwnResources()` turns on.
Confirmed with the maintainer: ERPNext is the only true multisite app today.

| | Not multisite (Nextcloud, WordPress, B1Church, ...) | Multisite (ERPNext) |
|---|---|---|
| Child has its own release | No: rebuilt inside the hub's | No: its site is made by a job against the hub's release |
| Child has its own database and sign-in settings | No | **Yes**: `CreateApplicationDatabase` on activation, own `databasename`, own `database_server_id` / `sso_server_id`, own LDAP/site identity |
| What a hub move adds to a normal move | Only the children's DNS and the acknowledgement | The above, plus the children's database pointers (and sign-in pointers if those move) |
| Data to restore | The hub's volume and database, as for any app | The database volume, restored with Longhorn like any other volume. The maintainer's view is that this is easy, so it is a manual step behind the same confirm gate, not new automation |

So for everything except ERPNext, a shared app move is **the same as moving any other
instance**, plus the checks and the DNS fan-out below. All of the real extra work is the
multisite branch, and it is small because the data is restored by hand.

## Parents with addon children (WordPress + CiviCRM)

The same `HAS_CHILDREN` block also stops a WordPress with a CiviCRM from moving, so this plan
covers it too. Confirmed with the maintainer: in this family the **child has a database of its
own, but signs in through the parent's SSO**. Reading the code agrees with the rest of it: the
child has `parent_id` set to the WordPress in the **same organization** (the plan's
`parent_app_id`), its jobs and updates run against the parent's release
(`ApplicationUpdate`/`ApplicationUpgrade` use the parent's `connect('web')` for an activation
type `job`), and its roles live in the parent's (`AppInstance::permissionsApp()`).

| | Shared hub (Nextcloud, ...) | Addon family (WordPress + CiviCRM) | Multisite (ERPNext) |
|---|---|---|---|
| Where the children are | Many organizations, in the hub's | Same organization as the parent | Many organizations |
| Own release | No | No (parent's) | No (job on the hub's) |
| Own database | No | **Yes**, on a database server | **Yes**, restored with Longhorn |
| Own sign-in | No | **No**: the parent's | **Yes** |
| DNS to refresh | Every child's domains | The organization's own | Every child's domains |

What a parent move does with an addon child:

1. **Web**: moves with the parent's release, as above. Lift `HAS_CHILDREN` for a parent whose
   children are addons (`parent_id` and activation type `job`), and add `ADDON_CHILD` for the
   child itself: a web or sign-in move of the child alone is refused ("move the parent").
   `IS_CHILD` does not cover it, because it only knows `sharedPlanParent()` children, and an
   addon child's `usesOwnResources()` is true.
2. **Database**: the child's database is not the parent's, so it is handled on its own. The
   common case is a new cluster with the database servers unchanged: the new app server only has
   to reach the child's database server, which is the check the move already makes for the
   parent's (`requirements()` and `CANNOT_REACH_DATABASE`), run for every child that has one and
   shown as one row each. If the administrator chooses a new database server, the parent's
   `BackupOldDatabase`/`RestoreNewDatabase` run again for each child with a database of its own,
   with the same dump driver, and `SwitchServer` repoints them, saved on the task for rollback
   like the multisite case. A child on a different database server than the parent's is a
   separate choice in the form, not a guess.
3. **Sign-in**: the parent's choice is the only one. If the child has a sign-in pointer, it is
   expected to be the parent's (to verify) and is repointed with it; if it is null there is
   nothing to do. The sign-in move of the parent is its own move (`ApplicationSsoMove`) and is
   not part of this one.
4. **Everything else is simpler than a hub**: one organization, so no acknowledgement of other
   organizations and no cross-organization DNS; the children are listed, and their domains
   (the plan's `domain_option` may put them under the parent's domain) are refreshed by the same
   `RefreshDns` loop.

## Rules specific to shared apps

1. **The hub moves, the children follow.** `docs/server-transitions-plan.md` already says "moving
   a parent moves its children; moving a child alone is meaningless". Keep `IS_CHILD`. Replace
   `HAS_CHILDREN` for a shared hub with checks on what the children need (below); keep it for
   anything that is not a shared hub (a type-level parent such as CiviCRM on WordPress has not
   been looked at, so it stays blocked until it is).
2. **Everyone on it is affected, so say so.** The move takes every child organization's site
   down while the release is scaled to zero. The confirmation lists the children (organization,
   domain) and the count, and asks for an explicit acknowledgement, in the style of the version
   test bypass (`acknowledge` => `accepted`).
3. **A child is not edited by the move, unless it is multisite.** Without multisite the hub's
   pointer is the only one that changes, and children are only *read* (their domains, their
   organizations) and, for DNS, re-published. A multisite child has its own database and sign-in
   pointers; those change only when the move was started with a new database or sign-in server,
   in the same step that changes the hub's (`SwitchServer`), and are put back by its rollback.
4. **The plan's servers are updated at the end, not the start.** The shared app's own plan
   (`plan.web_server_id` and friends, set during setup) is not used by an existing instance
   (`AppInstanceService::server()`), but it is what the setup page and the version tests show.
   After a successful cut-over the plan is pointed at the new server so the pages do not lie;
   rollback never touches it because it was not changed yet.
5. **Only a deployed, Kumuli-managed shared app can be moved.** `SharedAppContext::state()` must
   be `ACTIVE`. `SETUP` has nothing deployed (change the servers on its plan instead) and
   `EXTERNAL` is run outside Kumuli CP. The edit and move controllers already redirect the
   `EXTERNAL` case; the move action must refuse it too, so a crafted request can't.
6. **One move per hub, and nothing else running against it.** The hub's children queue
   `application_update_job` behind each other (`ApplicationUpdateJob`), and a child's activation
   or deletion rebuilds the hub's release. Refuse a move while any task is open for the hub or
   any of its children, and stop new child activations and plan changes while the move runs
   (see Phase 2).

## Phase 1: let a shared hub's web move through

Smallest slice that is correct on its own.

1. `MoveBlockers::app()`: split `HAS_CHILDREN` for web moves.
   - Hub is a shared app (`isSharedApp()`) with children whose plan is `server_type = shared`:
     no `HAS_CHILDREN`. There is no per-app allow-list: a hub that is not multisite moves like
     any other instance, and ERPNext is covered by Phase 3.
   - A parent whose children are addons (WordPress with CiviCRM): no `HAS_CHILDREN` either, and
     the child gets `ADDON_CHILD` for a web or sign-in move.
   - Anything else keeps `HAS_CHILDREN`.
   Update `MoveBlockersTest` ("refuses a web move for an app with child apps") for both cases.
2. `MoveBlockers::app()`: new `SHARED_NOT_ACTIVE` when `isSharedApp()` and
   `SharedAppContext::state() !== ACTIVE` (rule 5), and `CHILD_DATABASES_UNSUPPORTED` for a parent with children that have a database of their own (ERPNext, and WordPress with CiviCRM) until Phase 3 lands, `CHILD_TASKS_OPEN` when any child has an
   open task (rule 6). Messages in `resources/lang/{en,es}/messages.php` under `server_move`.
3. `ApplicationWebMove`: pass the hub's children into the description and into the task's
   custom values (`child_ids`) at construction, so the rollback and DNS steps work from what was
   true when the move started, not from whatever the children are later. A new child activated
   mid-move is stopped by rule 6, so this is a record, not a race fix.
4. `RefreshDns`: today it handles `$app_instance->domains()` only. For a hub, also handle each
   child's domains: copies of the old address (`OrgSubdomain.value === previous ip`) are set to
   the new one, and `UpdateDnsRecords` is run once per child organization for its managed
   domains. Domains the control panel does not manage go into **each child organization's** log
   with the new address (they are the child's customers' to change), plus one summary line on
   the hub. A failure here still must not fail the move (as now).
5. Hub-only effects of `Quiesce`/`SwitchServer`/`Verify`: no code change expected, since they
   go through the hub's release. Add tests that prove it for a hub with two children in two
   organizations (below).

Tests (Pest, fake driver, like `WebMoveTest`/`WebMoveWithServersTest`):

- move starts for a hub with two active children, and the old release is scaled down once;
- refused for `SETUP`/`EXTERNAL` hubs, with an open child task, and for a child;
- every child's managed domain gets `UpdateDnsRecords`, unmanaged ones are logged against the
  child's organization;
- rollback after a failure at each step leaves the hub on the old server and no child changed.

## Phase 2: the move page and entry points

1. Add a **Move** tab to the shared-app layout: `SharedAppContext::payload()['urls']['move']`
   (`{$instance}/move`, `null` unless `ACTIVE`), and the matching tab in the shared-app tabs
   component (`SharedAppView.vue`, `SharedAppTabs`). The same for the existing Edit tab's
   database and SSO buttons: they already work on the instance edit page under the shared
   layout, so only the discoverability is missing.
2. `Applications@move`: for a shared hub, add `children` to the page props (organization name,
   domain, whether the domain is managed) and render them above the steps with the
   acknowledgement from rule 2. The existing `move_web` validation gets `acknowledge` =>
   `accepted` when the app has children, plus a count in the success message.
3. **Hold the door while it runs**: while `application_web_move` is open for a hub, new child
   activations (`ApplicationActivate` prerequisites already have `add_application_required`) wait
   or are refused with a message, and the shared plan's edit page says a move is running. Pick
   one of "wait" or "refuse" with the owner of the subscription flow (Open questions 3).
4. Plan servers (rule 4): after `RefreshDns`, a new final step `UpdatePlanServers` sets the
   shared app plan's web server (and the database/SSO ones if they moved) to the new
   `Server`. It runs last so that rollback never has to undo it.
5. Announcement: offer to create an `Announcement` (existing `Admin\Announcements`) for the
   children's organizations before the move, with the planned window. Optional, off by default.

## Phase 3: children with a database of their own (ERPNext, CiviCRM) and what to check on a cluster

Both families need the web move to look after each such child's `database_server_id`; they
differ in how the data gets across (ERPNext: by hand, CiviCRM: the dump driver) and in sign-in
(ERPNext: the child's own, CiviCRM: the parent's). One shared piece of code does the child list
(`ChildDatabases`: the children of the app that have a `databasename`, with their server), and
the two differ only in the step that copies.

**CiviCRM (addon children).** Build this first, since it has no hand work:

1. `requirements()` and `blockers()` include each child's database server for the reach check.
2. If a new database server is chosen, repeat `BackupOldDatabase`/`RestoreNewDatabase` per child
   (a child-aware version of the two steps that records `child_database_backups` on the task),
   and have `SwitchServer` associate it with each child, saving the old pointers for rollback.
3. After `Verify`, run the settings job for the parent as now; the child's own update goes
   through the parent's release already.

Tests: a WordPress with a CiviCRM child on its own database server; the reach check names the
child's server; the copy and repoint run for both; rollback puts both back; the child cannot be
moved alone (`ADDON_CHILD`).

**Multisite children (ERPNext).** Each child has its own `database_server_id`,
`sso_server_id` and `databasename`. The existing web move only repoints and copies the hub's
own database (`BackupOldDatabase`, `RestoreNewDatabase`, `SetUpNewSignIn`, `SwitchServer`).
For a multisite hub:

1. The database volume is restored by hand with Longhorn, as the maintainer describes, and
   confirmed at the existing `AwaitVolume` gate. No dump/restore of each child: the child
   databases come across with that volume. The gate's message names every multisite child and
   the databases expected on the new server, so the administrator can check them.
2. If the move was started with a new database server, `SwitchServer` also associates that
   server with every multisite child (their `database_server_id`), saved in the task
   (`child_database_snapshot`) for its rollback. Confirmed with the maintainer: an ERPNext
   child's database is never on a different server than the hub's, so there is no per-child
   database choice. The move **blocks** if it finds one that is
   (`CHILD_DATABASE_DIFFERS`), rather than guessing: it means the data is not what this plan
   assumes.
3. **One sign-in server for the whole hub.** The form has a single sign-in choice, as for any
   app, and it applies to the hub and every multisite child. Children may start on different
   sign-in servers; they all end on the chosen one, which is what is wanted (today they are all
   on one server anyway). `SetUpNewSignIn` runs once per app that is moving, the hub first, then
   each child: each saves its own snapshot (`sso_snapshots[<instance id>]`), clears its stale
   ids, associates the chosen server and runs `ApplicationSSOSetup`, and the step waits for
   all of them. Rollback restores every snapshot. The old entries are left on the old servers,
   and `ApplicationSsoCleanup` is offered per app afterwards (the app page already lists one per
   move; children are listed under the hub's, with the old server named per child). The
   reachability check (`CANNOT_REACH_SSO`) is made against the one chosen server.
4. After `Verify`, run `RunSettingsJob` against the hub as now; for each multisite child also
   run its settings job (each child's site is made and migrated by a job on the hub's
   release, `ApplicationUpdateJob`, which already queues children behind each other).

Tests: a hub with two multisite children on the hub's database server and on different
sign-in servers; a move to a new database server and a new sign-in server repoints all three
apps to the one chosen of each and sets up a provider for each; a failure in `SwitchServer`
(or in the second child's sign-in setup) puts every database and sign-in pointer and every saved
setting back; a child on another database server blocks the move.

**Checks on a cluster (not code, and not blocking).** Record the results in the PR that
changes the hub's app; nothing here is allowed to fail the move:

| Area | What to check |
|---|---|
| Nextcloud hub (`NextcloudEnvVars`, `NextcloudJobChart`) | The directory-reader account (`ldap:provision-directory-reader`) is reachable from the new cluster, and its secrets exist in the new namespace (the hub's organization slug). Group folders are on the volume, so they move with it. |
| Ingress host list | Rebuilt from the non-deactivated children on the first release on the new cluster. Add a child-host check to `Verify` (log a warning per host that does not answer, do not fail the move). |
| TLS | One certificate per child host. cert-manager on the new cluster only issues them once DNS points there, so they appear after `RefreshDns`. Say so in the runbook. |
| Volume handover | Same as any app (`StorageHandover`). The volume holds every child's data, so the confirm message names the children. The hub's quota is what `StorageHandover::fields()` uses; children's `storage_quota` must not be added on top. |

## Phase 4: database and SSO moves for a hub

1. Surface the buttons on the shared-app Edit tab (already there through the instance edit page;
   check they render under the shared layout and `can_bypass`/state logic does not hide them).
2. `ApplicationDatabaseMigrate` for a hub: copy happens with the hub's database, which holds the
   children's data (verify per app, Phase 3). Add `CHILD_TASKS_OPEN` to its blockers and the
   children list with an acknowledgement on the modal.
3. `ApplicationSsoMove` for a hub: children's role groups and policy bindings are keyed by
   the hub's provider, so after `SetupSso` run the child group sync. The old provider cleanup
   (`ApplicationSsoCleanup`) already deletes by saved pk.

## Phase 5: clean up the old copy

`ApplicationWebCleanup` removes the old release by `uninstallRelease()`. For a hub, `delete()` on
the old server also rewrites redirects and, for charts that are "updated rather than removed",
reinstalls: that path must **not** be taken for a hub, because `KubernetesWebInterface::delete()`
on a child marks it deactivated. Cleanup must call `uninstallRelease()` on the old server's
interface only, and a test must prove no child's status changes.

## Build order

1. Phase 1 (blockers, task record, DNS fan-out, tests). Mergeable on its own; no UI yet, but the
   existing move tab already works for a hub once `HAS_CHILDREN` is lifted. Until Phase 3
   lands, keep a parent whose children have databases of their own blocked with its own reason
   (`CHILD_DATABASES_UNSUPPORTED`), since moving it without its children's pointers would
   leave them wrong.
2. Phase 2 (tab, children list, acknowledgement, plan servers, hold the door).
3. Phase 3: CiviCRM first (the dump driver does the copy), then the multisite branch. Remove
   `CHILD_DATABASES_UNSUPPORTED` for each family as it lands, so WordPress with CiviCRM can
   move before ERPNext does.
4. Phase 4 (database, SSO).
5. Phase 5 (cleanup).

Docs: add a "Shared app" subsection to `docs/server-transitions-plan.md` Phase 3 once Phase 1 is
merged, and a runbook appendix (Appendix D) in that file: pick a window; announce it to child
organizations; start the move; restore the volume; confirm; check every child host; update
unmanaged DNS in child organizations; wait; clean up the old release.

## Open questions

Answered: only multisite children (ERPNext today) have their own database and sign-in
settings, the database volume is restored by hand with Longhorn, ERPNext children are always on
the hub's database server, and one sign-in server is chosen for the whole hub. Everything else
moves like any other instance (see "Two kinds of shared app"). Still open:

1. For CiviCRM, does the child's `sso_server_id` hold the parent's server, or is it null? And is
   its database always on a server of the same driver as the parent's? Both are read off
   `ApplicationActivate` for an addon, which nobody has checked yet; the plan works either way
   but step 3 of the addon section depends on it. (Resolved: the family is in scope, with its
   own database and the parent's sign-in.)
2. During a move, should a child activation wait for the move or be refused? Waiting is friendlier
   but holds a subscription flow open for up to the move's timeouts (about 1.5 hours in total).
3. Should the announcement to children (Phase 2, step 5) be sent automatically for apps with
   more than N children, or always left to the administrator?
4. Resolved: an ERPNext child's database is never on a different server than the hub's, and one
   sign-in server is chosen for the hub and all its children (Phase 3). Nothing left to decide.
