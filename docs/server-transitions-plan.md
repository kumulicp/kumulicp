# Plan: moving an app instance to another server

Status: proposed. Nothing below the "Where we are" section is built yet. It records what
the code does today, which parts of a move can be automated with it, which need new code,
and which stay manual, so the work can be built in order.

## Where we are

An app instance is tied to three servers through `org_servers` rows
(`app_instances.database_server_id`, `web_server_id`, `sso_server_id`). An organization
server has an optional `backup_server_id` (another `org_servers` row, which may be itself).

| Piece | State |
|---|---|
| Admin edit page: pick the app's database / app (web) / SSO server | Built. It **only changes the pointer**: no data moves, nothing is redeployed, nothing is cleaned up on the old server. |
| Admin organization "Servers" tab: pick a server's backup server | Built. Same: it only changes the pointer. |
| `ApplicationDatabaseMigrate` (backup, restore, cut over, update, roll back) | Built, reachable from "Migrate Database" on the same page. |
| Moving an app's web server | Not built. |
| Moving an app's SSO server | Not built. |

So today a raw pointer change is a repair tool (the data is already where the pointer says),
and it is **not** a way to move an app. The raw database select in particular overlaps with,
and is more dangerous than, Migrate Database. Every move below should be a task, with
rollback, started from a button that says what it will do.

### Vocabulary used in the tables

| Label | Meaning |
|---|---|
| **Auto** | Works with code that exists today. |
| **Auto (new)** | Can be automated, but needs new code. |
| **Assisted** | The system does the work once an admin has entered something (a value, a credential, a confirmation). |
| **Manual** | Done by a person outside kumulicp (the other server's UI, the cluster, the registrar). |

## Rules that apply to every move

1. **A move is a task, not a form save.** It is a `StepAction` (`docs/actions.md`, "Steps")
   in the shape of `ApplicationDatabaseMigrate`: `Prepare` records where the app is and where
   it is going, the middle steps do the work, `CutOver` points the app at the new server and
   runs the standard `ApplicationUpdate`, and `Prepare::rollback` undoes the cut-over from what
   it recorded. The old server is never modified before the app is confirmed healthy on the new
   one, so rollback is always "point back and update".
2. **Choose a `Server`, not an `org_servers` row.** The target is usually a server the
   organization is not on yet. `OrgServerService::forServer()` creates the binding (and its
   backup binding). The current dropdowns only list rows that already exist, so they cannot
   reach a new server. Moves should list eligible `Server`s of the right type and bind on start.
3. **Check before touching anything** (`blocker()`, as the database migration does): same
   organization, right server type, target reachable (`Servers@testSettings`), the app is
   `active`, no other task running for the app (`Action::execute` already turns away a
   duplicate), and no child apps depend on it (see "Web server").
4. **Drift.** `ReleaseAspect` fingerprints `web_server_id` and `SsoAspect` fingerprints the SSO
   server ids, so a pointer change already shows the instance as out of date. A move task
   should clear the pending update (`Action::clear('application_update', ...)`) before it
   runs its own, as `CutOver` does.
5. **The plan's server is not the instance's server.** Once an instance has a server, changing
   the plan's server does nothing for it (`AppInstanceService::server()`). Each instance is
   moved on its own; there is no bulk move for now, and a plan edit can't stand in for one.
6. **Leave the old copy alone.** Do not delete the old database, release, provider or volume as
   part of the move. Offer "clean up the old server" as a separate, later action.

## 1. Database server

Built: `ApplicationDatabaseMigrate` (`PrepareMigration` -> `BackupDatabase` ->
`RestoreDatabase` -> `CutOver`), with timeouts, polling and rollback.

| Step | Class | Status |
|---|---|---|
| Check the move is possible | `ApplicationDatabaseMigrate::blocker()` | Auto |
| Bind the organization to the new server | `OrgServerService::forServer()` | Auto |
| Fresh backup on the old server | `BackupDatabase` (`app_database` mysqldump driver) | Auto |
| Restore into the new server (creates the database and user) | `RestoreDatabase` | Auto |
| Point the app at it, run `ApplicationUpdate` so the chart gets the new host | `CutOver` | Auto |
| Roll back if any step fails or the app does not come up in 20 minutes | `PrepareMigration::rollback` | Auto |
| Add the new `Server` (host, internal address, credentials, backup driver) | Admin > Servers | Manual |
| The new server accepts connections from the app's cluster (firewall, network policy) | cluster / network | Manual |
| Same engine and a compatible version as the old server | | Manual |
| Writes made to the app during the copy are not carried over | | Manual: pick a quiet time (see gap below) |
| Delete the old database once the new one is trusted | | Manual (an "old copy" cleanup action is Auto (new)) |

Gaps worth closing:

- **Only a MySQL-style dump driver exists** (`BackupService` registers `database ->
  app_database`). A server whose engine that driver can't dump is blocked by
  `Backup::supportsServerTargets()`; that is correct, but the page should say why.
- **Quiesce writes.** The migration copies a live database. For apps where lost writes matter,
  add an optional first step that scales the release to 0 (the `expand_storage` path already
  sets `replicaCount` to 0) and `CutOver` brings it back. Auto (new).
- **Retire the raw database select** on the edit page, or label it "Reassign without moving
  data" and keep it for repair only.

## 2. SSO server (Authentik)

How it works (from the code): Authentik holds **no users or passwords**. Users and groups live
in kumulicp's own LDAP; Authentik reads them through an LDAP *source*. Per app, kumulicp
creates an OIDC provider, an application and one policy binding per role group, and stores the
generated client id and secret on the instance (`settings.sso_client_id`,
`sso_client_secret`, and `settings.sso` with Authentik's own ids). Names are deterministic
(`{app_id}-{org_slug}-{app_name}`), so they are identical on a new server. So a move is
per-app work, not a user migration.

| Step | How | Status |
|---|---|---|
| Deploy the new Authentik and its first admin | `ServerActivate` or Helm, then `/if/flow/initial-setup/` | Manual |
| Create a service account and API token | Authentik UI | Manual |
| Create the **LDAP source** (same bind DN and base DN as kumulicp's LDAP) | Authentik UI | Manual |
| Create the group property mapping that sets the `distinguishedName` attribute | Authentik UI | Manual. Provider setup throws `SSONotReadyException` until it exists. |
| Create flows, signing key, property mappings, certificates, branding, any outposts | Authentik UI | Manual |
| Add the `Server` (address, `api_key` = `Bearer`, `api_secret` = token) and enter `signing_key`, `invalidation_flow`, `authorization_flow`, `authentication_flow`, `property_mappings`, optional `ldap_source_slug` | Admin > Servers | Manual (the other default settings are filled in automatically) |
| Verify the server | `Servers@testSettings` (`AuthentikConfigTest`: throwaway provider, application, group, binding) | Auto. It does **not** check the LDAP source or the group mapping, so add that check: Auto (new). |
| Bind the organization to the new server | `OrgServerService::forServer()` | Auto |
| Delete the instance's provider and application on the old server | `delete()` on the old server's interface, run last as its own action | Auto (new). The API removes by the **pk stored in the instance's settings**, which after the move is the new server's: the cleanup must use the old pk saved by the move, or it deletes the wrong provider. |
| **Clear** `settings.sso`, `sso_client_id`, `sso_client_secret` on the instance | | Auto (new). Required: a stale `provider_obj.pk` is looked up on the new server and throws or hits an unrelated provider. |
| Set `sso_server_id` to the new binding | `CutOver` | Auto (new) |
| Create provider, application, client id/secret, policy bindings on the new server | `ApplicationSSOSetup` | Auto |
| Re-render the app with the new issuer URL and secret (release + settings job) | `ReleaseStep` and `SettingsJobStep`, then `SsoStep` | Auto, but `SsoStep` has no ordering, so the move must run the three in order |
| LDAP sync into the new Authentik | `Sources::LDAPSync` (runs inside add/update) | Auto |
| Apps that do not read the stored SSO values: **CiviCRM** and generic apps get a provider but no wiring | | Auto (new) per app, or Manual |
| Users sign in again (sessions and cached tokens do not move) | | Manual (announce it) |
| Decide `sub_mode`: the default `hashed_user_id` may give users **different `sub` values** on a new Authentik, which matters for apps that key users on `sub`. Nextcloud keys on `preferred_username` by default. | Server settings | Manual decision (inference, not tested) |

New code: `ApplicationSsoMove` (`StepAction`): `Prepare` (record old/new binding, snapshot the
old `settings`), `Clear` (remove the stale ids), `CutOver` (swap pointer, run
`ApplicationSSOSetup`, then the release/settings/SSO update), `rollback` (restore the snapshot
and the old pointer, re-run setup on the old server). Old provider deletion is a separate,
last step so rollback never has to recreate it.

If `account_manager.driver` is not LDAP, there is no SSO user sync in the code at all; user
sync to the new Authentik is then entirely manual.

## 3. Web (app) server

How it works: `web_server_id` points at a Kubernetes (or Rancher) `Server`. The namespace is
the organization slug and is created on first connect. Deploys are `helm upgrade --install`
run as a Job in the cluster. The release name is stored in the instance's overrides, so it is
the same on a new cluster.

### What moves with the app, and what does not

| Item | Moves? | Status |
|---|---|---|
| Namespace, ingress, chart secrets, image-pull secrets, Helm repository credentials | Re-created on install | Auto |
| Release name, chart values, instance settings | Stored on the instance | Auto |
| Database | Not on the cluster | Unchanged (or a separate database migration) |
| **Persistent volume data** (files) | No | See below |
| DNS | `OrgSubdomain.value` is a **copy of the old server's IP**; not refreshed | Auto (new): refresh it, then `UpdateDnsRecords` for managed domains. Other registrars: Manual |
| TLS | cert-manager on the new cluster needs the same ClusterIssuer (`letsencrypt-production`/`-staging`) and DNS already pointing at the new ingress | Manual |
| Cluster access | `Server` record needs address, CA cert and a token; the `kumulicp-deployer` RBAC must be applied (`docs/k8s-rbac-sample.yaml`) | Manual |
| Image registry reachable from the new cluster; storage class exists; ingress controller installed | | Manual |
| Child apps (`parent_id`, shared apps, job charts) | Rebuilt as part of the parent's release | Moving a parent moves its children; moving a child alone is meaningless. Block it. |
| Old release, PVC and Helm release secrets | Stay on the old cluster | Manual cleanup, or an "old copy" action (Auto (new)) |

### Persistent volume handover (the hard part)

What the code does:

- PVC names come from per-chart code and are stable: Nextcloud `<org>-nextcloud-<id>-nextcloud`,
  generic charts `<fullname>-<volumeName>`. On a new cluster the chart would ask for the **same
  name**.
- Size comes from `storage_quota`/`chart_storage` (`HelmChart::appStorage()`), access mode and
  storage class from per-profile configurations (`persistence-accessMode`,
  `persistence-storageClass` and similar; Nextcloud defaults to RWO).
- An "existing claim" override exists but is **inconsistent per chart**: Nextcloud reads
  `setting('existing_claim')`; WordPress and HelmForge read `override.pvc.name`; CiviCRM needs
  `override.pvc.override` as well; generic charts use `persistence-N-existingClaim`. There is
  no screen for any of them, only the raw settings JSON.
- With an existing claim the chart creates no PVC, so size, class and mode values are ignored.
- Nothing sets `helm.sh/resource-policy: keep`, so `helm uninstall` removes chart-created PVCs;
  whether the data survives depends on the StorageClass reclaim policy, which kumulicp does not
  control.
- There is **no volume backup driver** (no Longhorn, restic or Velero integration). The only
  backup driver is a database dump, and a `web` backup request is silently ignored.
- The deployer's RBAC has PVC verbs but **no `persistentvolumes` access**, so kumulicp cannot
  pre-create a PV with a `claimRef`.

The collision: restoring a volume from a Longhorn backup on the new cluster produces a PV/PVC
(often with the old claim name or a `claimRef` to it). If the chart then installs with the
same claim name, Helm refuses the PVC it did not create, or two claims fight over one PV. The
fix is to **install against a claim that is already there**, under a name chosen on purpose.

Steps:

| Step | Status |
|---|---|
| Quiesce the app (scale to 0) so the volume is consistent | Auto (new); reuse the `expand_storage` mechanism |
| Take the volume backup on the old cluster | Manual (Longhorn UI/schedule). Auto (new) only if a volume backup driver is added. |
| Restore the volume on the new cluster from the backup target | Manual (Longhorn) |
| Create the PV/PVC with the right `claimRef`, **new claim name**, size, `ReadWriteMany`/`ReadWriteOnce`, storage class | Manual (the deployer cannot create PVs); the PVC alone may be Auto (new) |
| Enter, in kumulicp, the **new claim name**, size, access mode and storage class | **Assisted**: a form on the move (see Phase 3). Written to the chart's existing-claim key by a per-chart mapping, with the size/mode shown for the admin to check against the PV. |
| Confirm the volume is ready | **Assisted**: a "Continue" gate (below) |
| Install on the new cluster (`ReleaseStep`) against the claim | Auto |
| Verify the app is up (readiness) | Auto |
| Cut over: DNS, then retire the old release | DNS Auto (new) / Manual; retire is a separate action |

New code needed for a web move:

- `ApplicationWebMove` (`StepAction`): `Prepare` -> `Quiesce` -> **`AwaitVolume`** ->
  `Install` (on the new server, with the claim override) -> `Verify` -> `CutOver` (pointer,
  DNS refresh, update). Rollback re-points at the old server and scales it back up. Because
  the old release is untouched until cut-over, rollback is cheap.
- **`AwaitVolume`**: a step that returns `wait()` until an admin confirms, with a timeout. The
  task stays visible in Tasks with a message saying what to do and a Continue button. The
  step framework supports waiting and polling, but a human gate is new (a custom value the
  Tasks page sets).
- A **claim handover mapping** per chart profile: one function that turns `{claim, size,
  accessMode, storageClass}` into that chart's override keys, so the five spellings above are
  hidden from the admin. Also fixes the inconsistent keys.
- `blocker()` for web moves: same organization, target is a web `Server` of the same driver
  family (Rancher vs Kubernetes values differ), the app is active, has no children, and is not
  a shared-app child.
- A **volume backup driver** (Longhorn first) is a separate, larger decision; the plan above
  works without it by treating the volume as manual.

## 4. Backup server (organization server)

`OrgServer::backup_server_id` is the server a given server's backups go to. It can be the
server itself (`OrgServerService::addBackupServer`).

- Changing it only affects **future** backups. Existing `org_backups` rows remember their own
  `org_server_id`, so old backups can still be restored from where they were taken. Nothing is
  copied.
- A backup server has the same `type` as the server it backs up, and `servers.is_backup_server`
  set (`Admin\Servers`). The edit validates this now.
- Moving to a backup server the organization is not on yet needs the binding created
  (`forServer`): same gap as above.
- Copying old backups to the new server is Manual unless the backup driver gains a copy
  operation (Auto (new)).
- Web backups silently do nothing today (no driver, `BackupService::schedule` returns early,
  and the controller still reports success). Fix that independently: return an error.

## Build order

**Phase 0 - what this branch ships.** Pointer editing on the app edit page and the Servers tab,
with validation (same organization, right type, backup servers of the same type), no
accidental clearing, no change while the app is not `active`, a note on the edit page that
the app server fields only reassign (nothing is moved; use Migrate Database for the
database), and tests.

**Phase 1 - shared groundwork.** Done (#257 server choices, #258 move/repair UI, #259 backups with
no driver are an error, #260 `MoveBlockers` and the base move action, which report every blocker at
once).

- Eligible-server lookup by `Server` type with `forServer()` on save (replaces org-row-only
  dropdowns). **Done** (`App\Support\ServerChoices`): the app edit page and the Servers tab
  offer any active server of the right type, and bind the organization to it when picked.
- A shared `MoveBlockers` and base class for the three moves.
- Replace the raw selects with a **Move** button per server type (database -> existing
  Migrate Database; web and SSO -> the new actions), keeping "reassign without moving data"
  behind an explicit, labelled repair option.
- Fix the silent `web` backup no-op.

**Phase 2 - SSO move.** Done, as a stack of four PRs: the LDAP-source check in the Authentik server
test; `ApplicationSsoMove` (snapshot and clear the stale ids, set up on the new server, then the
standard update, with rollback); the Move Sign-in button and route; and `ApplicationSsoCleanup`,
which removes the old server's provider by the pk the move saved. One rule came out of it: an app
cannot be moved back to a server that still has its old entry, because setup would find that
provider instead of making one with a client id and secret, so the entry has to be removed first.
Still open from this phase: wiring for apps that do not read the stored SSO values (CiviCRM and
generic apps get a provider but no configuration). Runbook in Appendix A.

**Phase 3 - web move**, built as a stack, each slice based on the one before:

1. The storage handover (`App\Support\StorageHandover`): one mapping from a claim name, access mode,
   storage class and size to the settings each chart reads (`AppProfile::storageHandover()`), with
   what was there before kept so it can be put back. A new volume clears the claim name the old
   volume may have left in the settings.
2. A "wait for an admin to confirm" step, and the button on the app page that confirms it.
3. `ApplicationWebMove`: prepare, scale the old release to zero (its own flag, not
   `expand_storage`), wait for the volume, hand over the storage and flip the pointer and run the
   standard update on the new server, check it is up, refresh the DNS records. Rollback
   restores the settings, points back and scales the old release up again.
4. The Move button on the app page, with the target server, and the route.
5. Removing the old release, as its own action (`delete()` on the old server's interface has
   side effects on the old server's redirects that need care).

All five are done, as a stack of six PRs (the move is in two: stop and wait for the volume, then install, check and
refresh the DNS). Notes that came out of building it:

- The pointer is flipped before the install, since several chart pieces read the instance's web server. The old
  release is only scaled down (`quiesced_for_move`), never removed, until the cleanup.
- The cleanup asks the old server to `uninstallRelease()`. Its `delete()` also rewrites the app's redirects on that
  server and, for the charts that are updated rather than removed, installs the app there again.
- Rancher and Kubernetes servers can't be moved between: their values differ.
- Domains the control panel doesn't manage are listed in the organization's log with the new address.
- Still open: a volume backup driver (the volume is restored by hand), and the other charts' claim keys have only
  been checked against their code, not against a cluster.

Runbook in Appendix B.

**Phase 4 - polish.** Backup copy between servers, optional volume backup driver, `sub_mode`
guidance in the server form. No bulk move for now: each app is moved on its own.

Tests for each phase follow the existing pattern: action tests with a fake driver, feature
tests for the controllers and their validation, and a rollback test for each step.

## Open questions

1. Should the raw pointer edit stay at all, behind a "repair" label, or go once Move exists?
2. Is Longhorn the only volume backend, or should the handover form be backend-neutral (claim
   name, size, mode, class only)?
3. Single-organization installs hide the organization tabs, so the Servers tab is not
   reachable there. Where should it live (Admin > Servers, per server)?
4. Do we want `AwaitVolume` to be a generic "manual gate" step for any action that needs a human
   in the middle (it would also suit SSO's LDAP-source prerequisite)?

## Appendix A - runbook: new SSO server

1. Deploy Authentik; complete the initial setup; create a service account and API token.
2. Create the LDAP source with kumulicp's LDAP bind DN and base DN; add the group
   `distinguishedName` property mapping; create the flows, signing key and property mappings.
3. Add the `Server` in Admin > Servers (type SSO, interface Authentik); enter the settings;
   press Test Settings.
4. Decide `sub_mode` before the first app moves.
5. Move one low-risk app; check sign-in, groups and the redirect URL; then move the rest.
6. Tell users to sign in again. Delete the old Authentik entries once everything is moved.

## Appendix B - runbook: new web server (cluster)

1. Cluster ready: storage class (and reclaim policy), ingress controller, cert-manager with
   the same ClusterIssuer names, registry pull access, network access to the database server.
2. Apply the `kumulicp-deployer` RBAC; create the token; add the `Server` (address, CA cert,
   token); press Test Settings.
3. Per app: pick a quiet time; start the move (the app scales to 0).
4. Back up the volume on the old cluster; restore it on the new one; create the PV/PVC under a
   **new** claim name, with the size and access mode (RWX if the chart needs it) the old claim
   had.
5. Enter the claim name, size, access mode and storage class on the move; press Continue.
6. Check the app is up on the new cluster before DNS is pointed at it (hosts file or
   port-forward), then let the move finish; for non-managed domains change the A record by hand.
7. Keep the old release and volume until the app has run for a while; clean up with the
   old-copy action.

## Appendix C - runbook: new database server

1. Add the `Server` (type database, host, internal address, credentials, backup driver);
   check the app's cluster can reach it and the engine/version matches.
2. Press **Migrate Database** on the app; choose the new server. (Quiet time recommended: writes
   during the copy are lost.)
3. Watch the task; on failure the app is back on the old database with a message saying so.
4. Delete the old database after the retention period (the safety backup is kept 14 days).
