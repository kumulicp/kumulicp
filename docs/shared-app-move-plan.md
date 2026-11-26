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
| Database (`ApplicationDatabaseMigrate`) | Allowed by `MoveBlockers` (only a web move checks for children). Not reachable from the shared app pages. Children are not considered at all (see "Open questions" 1). |
| SSO (`ApplicationSsoMove`) | Allowed for the hub. Children (`IS_CHILD`) are refused, which is right: they sign in through the hub. Not reachable from the shared app pages. |
| Web (`ApplicationWebMove`) | **Blocked** by `MoveBlockers::HAS_CHILDREN` as soon as one child is not deactivated, which is every hub that is in use. |
| Pointer edit (repair) | Reachable on `/admin/organizations/{shared org}/apps/{hub}/edit`. |

The pages are already there: the move tab is `/admin/organizations/{org}/apps/{app}/move`, and
`SharedAppContext::fromRequest()` already wraps any page whose route has an `app` parameter
that is a shared app in the shared-app layout. But the shared-app tabs
(`SharedAppContext::payload()['urls']`) have no link to it, so there is no way to get there
from `/admin/service/shared-apps/{id}`.

So "move shared apps" is mostly **web move**: lift the children block safely, and carry the
children's side of the move (DNS, role groups, database) with the hub. Database and SSO moves
need the buttons surfaced and a check for what they do to children.

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
3. **No child is edited by the move.** Children have no server of their own that matters (verify,
   see "Open questions" 2); the hub's pointer is the only one that changes. Children are only
   *read* (their domains, their organizations) and, for DNS, re-published.
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
     no `HAS_CHILDREN`; instead `CHILDREN_UNSUPPORTED` when the hub's application has no
     per-app support yet (a short allow-list in the app profile, starting with the apps we can
     test: see Phase 3).
   - Anything else keeps `HAS_CHILDREN`.
   Update `MoveBlockersTest` ("refuses a web move for an app with child apps") for both cases.
2. `MoveBlockers::app()`: new `SHARED_NOT_ACTIVE` when `isSharedApp()` and
   `SharedAppContext::state() !== ACTIVE` (rule 5), and `CHILD_TASKS_OPEN` when any child has an
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

## Phase 3: per-app behaviour (the part that needs real clusters)

The chart builders for each shared app do things with children that a move has to survive, and
none of it has been checked on a cluster:

| Area | What to verify or change |
|---|---|
| Nextcloud hub (`NextcloudEnvVars`, `NextcloudJobChart`, group folders) | The widened LDAP base and the directory-reader account must be reachable from the new cluster. `ldap:provision-directory-reader` secrets must exist in the new namespace (namespace is the hub's organization slug: `shared`). Group folders live on the volume, so they move with it. |
| B1Church / WordPress / ERPNext hubs | Per-site databases or sites inside the hub's database and volume. Confirm every child's data is inside the one volume and one database that the move copies; if a child has its own database server (`database_server_id` on the child), the move must also copy that (see Open questions 2). |
| Ingress host list | Rebuilt from the non-deactivated children on the first release on the new cluster: no work, but `Verify` should check every child's host answers on the new cluster, not only the hub's. Add a child-host check to `Verify` (warn in the log, do not fail the move). |
| TLS | One certificate per child host: cert-manager on the new cluster issues them only after DNS points there, so expect them to appear after `RefreshDns`. Say so in the runbook; consider an HTTP-01 pre-check to avoid downtime on the children's hosts. |
| SSO | A hub moved to a new SSO server (separate move) keeps working for children only if the group role bindings are per child: re-run `AddLdapGroups`/role setup for each child. Check `ApplicationSsoMove` on a hub with children. |
| Volume handover | Same as any app (`StorageHandover`), but the volume holds every child's data, so the confirm step's message names the children and the size. The `storage_quota` of children must not be double counted: `StorageHandover::fields()` uses the hub's quota. |

Start with the one app we can test end to end and open the allow-list from Phase 1 one app at a
time. Each app added needs: a feature test with a fake driver, a cluster run recorded in the
PR, and a line in the runbook.

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
   existing move tab already works for a hub once `HAS_CHILDREN` is lifted, so keep the allow-list
   empty by default and enable it per app.
2. Phase 2 (tab, children list, acknowledgement, plan servers, hold the door).
3. Phase 3, one app per PR, then enable it in the allow-list.
4. Phase 4 (database, SSO).
5. Phase 5 (cleanup).

Docs: add a "Shared app" subsection to `docs/server-transitions-plan.md` Phase 3 once Phase 1 is
merged, and a runbook appendix (Appendix D) in that file: pick a window; announce it to child
organizations; start the move; restore the volume; confirm; check every child host; update
unmanaged DNS in child organizations; wait; clean up the old release.

## Open questions

1. Do any child apps have their own database (or their own SSO provider) on a different server
   from the hub's? Read `ApplicationActivate` for shared children and `AppPlan` for `shared`
   plans to confirm what a child's `database_server_id`/`sso_server_id` are. If they are set,
   the hub's move must also repoint or copy them; if they are null or equal to the hub's, nothing
   to do. This decides Phase 1 step 4 and Phase 3.
2. Is `server_type = shared` the only way a child exists? Type-level parents (CiviCRM on
   WordPress, `activation_type = job`) also use `parent_id`. Out of scope here; are they
   expected to be movable too, since they are the same `HAS_CHILDREN` block?
3. During a move, should a child activation wait for the move or be refused? Waiting is friendlier
   but holds a subscription flow open for up to the move's timeouts (about 1.5 hours in total).
4. Should the announcement to children (Phase 2, step 5) be sent automatically for apps with
   more than N children, or always left to the administrator?
5. Is there a shared app whose hub's volume cannot be moved by hand (no restore path), so it has
   to stay out of the allow-list for now?
