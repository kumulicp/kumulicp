# Plan: extensible PVC restore for disaster recovery (Longhorn first)

Status: proposal. Nothing here is implemented yet.

## 1. Is it feasible?

**Yes.** The `Volume` manifest in the request looks like it needs a lot of
prior knowledge, but almost all of it can be read from the cluster instead of
typed in or computed:

| Field in the Longhorn `Volume` | Where it really comes from |
| --- | --- |
| `fromBackup` | The Longhorn `Backup` CR's `status.url`. Longhorn already stores the full `s3://bucket@region/path?backup=…&volume=…` string, so we never hand-build it. |
| `size` | The same `Backup` CR's `status.size` (a string of bytes). Copy it verbatim. This is the "must match exactly" value. |
| `numberOfReplicas` | Server setting (default 3), optionally overridden per restore. |
| `metadata.name` | Generated: `<pvc>-restore-<yyyymmddhhmm>`. |

Longhorn publishes `Backup` and `BackupVolume` CRs in `longhorn-system` once a
`BackupTarget` is configured and synced. That includes a fresh cluster pointed
at the old bucket, which is the actual DR case. So "list restore points" is a
`kubectl get backups.longhorn.io -n longhorn-system -o json`.

**Caveats that make this more than "render one YAML":**

1. A restored `Volume` is not usable by a workload yet. We also need a `PV`
   (CSI, `driver.longhorn.io`, `volumeHandle` = Volume name) and a `PVC` that
   binds to it. The `Volume` is step 1 of 3.
2. Restore is asynchronous. The PV/PVC must not be created, and the app must not
   be started, until Longhorn reports the restore finished. Field names
   (`status.restoreStatus`, `status.restoreRequired`, `status.state`) must be
   verified against the Longhorn version we deploy.
3. On a rebuilt cluster the backup target must be configured, its credentials
   Secret present, and its poll must have run before any `Backup` CR exists.
   We can force a sync by touching `BackupTarget.spec.syncRequestedAt`.
4. **Multi-tenancy.** KumuliCP is multi-tenant: one namespace per organization.
   Restore points are cluster-wide. We must prove a backup belongs to the
   requesting organization before restoring it. Otherwise org A could restore
   org B's data. See section 5.
5. Charts consume PVCs inconsistently today (section 3), so "restored a PVC"
   does not yet mean "the app uses it".

## 2. Design: one interface, pluggable providers

KumuliCP already has the extension pattern we need: `BackupService`
(`app/Services/BackupService.php`) is a registry bound as the `backups`
singleton in `ActionServiceProvider` with a `Backup` facade, plus
`ServerInterfaceService` for server drivers. We copy it rather than inventing
something new.

```
app/Contracts/ServerManager/VolumeRestoreContract.php
app/Services/VolumeRestoreService.php              bound as 'volume_restores'
app/Support/Facades/VolumeRestore.php
app/Integrations/ServerManagers/HelmKubernetes/Restore/
    LonghornRestoreProvider.php                    phase 1
    CsiSnapshotRestoreProvider.php                 phase 6 (generic CSI)
    Manifests/LonghornVolumeManifest.php           pure functions, no I/O
    Manifests/PersistentVolumeManifest.php
    Manifests/PersistentVolumeClaimManifest.php
    RestorePoint.php  RestoreRequest.php  RestorePlan.php   (readonly DTOs)
```

```php
interface VolumeRestoreContract
{
    /** @return RestorePoint[] restore points the organization is allowed to see */
    public function restorePoints(Organization $org, ?AppInstance $app = null): array;

    /** Pure and side-effect free: builds the ordered manifests + steps. */
    public function plan(RestoreRequest $request): RestorePlan;

    public function apply(RestorePlan $plan): RestoreResult;   // idempotent, resumable

    public function status(RestorePlan $plan): RestoreStatus;  // pending|restoring|ready|failed
}
```

- `RestorePoint`: provider, id, source volume, `sizeBytes` (string), created at,
  provider URL, state, source namespace and PVC (when known).
- `RestorePlan`: ordered list of manifests, e.g. Volume, PV, PVC for Longhorn.
  `plan()` being pure is the key property. It gives us a **dry-run/preview**
  (shows the YAML from the request), golden-file unit tests with no cluster,
  and an audit trail.
- Registration mirrors `Backup::register($type, $name, $class)`:
  `VolumeRestore::register('longhorn', LonghornRestoreProvider::class)`, so
  another module or package can add Velero, a cloud-provider snapshot, etc.
- Provider selection: Server setting `restore_provider` on the `helm_k8s`
  server. If unset, auto-detect by checking for CRDs (`volumes.longhorn.io`,
  then `volumesnapshots.snapshot.storage.k8s.io`).

### Longhorn plan output (what `plan()` produces)

1. `Volume` (`longhorn.io/v1beta2`, ns `longhorn-system`): exactly the manifest
   in the request, with `fromBackup`/`size` taken from the `Backup` CR.
2. `PersistentVolume`: capacity = `size`, `persistentVolumeReclaimPolicy:
   Retain`, `csi.driver: driver.longhorn.io`, `csi.volumeHandle` = Volume name,
   `fsType`, `storageClassName`, and a **`claimRef` pre-bound to the target
   namespace/PVC** so nothing else can claim it.
3. `PersistentVolumeClaim` in the org namespace with `volumeName` = the PV and
   `requests.storage` = `size`.

Alternative the provider can offer later: a throw-away `StorageClass` with
`parameters.fromBackup`, which lets a normal PVC provision from a backup. It
needs fewer objects but creates a cluster-scoped resource per restore, so it
is not the default.

## 3. Existing code that this touches

Findings from reading the repo (verify as you go):

- `HelmKubernetes/API/PersistentVolumeClaim.php` and the Rancher equivalent
  have **no callers**. PVCs are created by the Helm charts themselves
  (`persistence.enabled`). Reuse the class for manifest creation or replace it
  with the new manifest builders. Don't leave two PVC code paths.
- `KubectlCli` (`HelmKubernetes/Support/KubectlCli.php`) has `apply/get/delete`
  but no `list` (with `-l`), `wait`, or `patch`. Add `list()` and `wait()`.
  `apply()` pipes JSON through stdin with an argv array, so there is no shell
  injection surface as long as we keep it that way.
- `BackupService::driverExists()` indexes `$this->drivers[$type]`, and only
  `database` is registered. For a `web` server this looks like an
  undefined-index error before the `try` in `ActivateBackup`. Fix with `?? []`
  as a prerequisite, and register under `web` => `helm_k8s`.
- Restore bookkeeping already exists: `OrgBackup` (`action = 'restore'`,
  `settings` JSON, `job_id`, `status`) plus `ActivateBackup` dispatching
  `restore()`. Reuse it with `type = 'volume'`. Store the serialized
  `RestorePlan` in `settings` and the Longhorn Volume name in `job_id`. Add no
  new table in phase 1.
- Charts disagree on how to point at an existing PVC:
  `NextcloudChart` reads `setting('existing_claim')`, `WordpressChart` reads
  `getOverride('pvc.name')`, `CiviCRMStandaloneChart` reads
  `getOverride('pvc.override')` + `pvc.name`. Standardize on
  `override.pvc.name` + `override.pvc.override = true` via one
  `AppInstance` helper, and have all three charts use it.
- Async completion: follow the existing task-completion polling used by
  `ApplicationUpgrade::complete()` rather than blocking the queue worker.
- Rancher driver: out of scope for phase 1. It would need Steve-API access to
  Longhorn CRs (`/v1/longhorn.io.volumes`). The interface keeps it possible.

## 4. Restore flow (per app)

Safe by default: **never overwrite the live PVC.** PVC names are immutable, so
we restore to a new name and repoint the chart.

1. Admin picks an app, then a restore point (list from `restorePoints()`).
2. `plan()` renders the manifests; UI shows a preview (the YAML).
3. On confirm, create an `OrgBackup` row (`action=restore`, `type=volume`).
4. `apply()`:
   1. Scale down or `helm uninstall` the release while keeping data (the old
      PVC is retained, not deleted).
   2. Create the Longhorn `Volume`. Poll `status()` until restored.
   3. Create PV, then PVC. Wait for `Bound`.
   4. Set `override.pvc.name` to the new claim and `override.pvc.override=true`.
   5. Run the normal `ApplicationUpgrade` path so Helm reinstalls against the
      restored claim.
5. Mark `completed`. Leave the old PVC and old Volume for a manual,
   explicitly confirmed cleanup.

**True DR (new, empty cluster):** an option `target_name = original` restores
into the original PVC name so charts need no override at all. It's only
allowed when no PVC of that name exists.

Databases (the existing `AppDatabase` mysqldump flow) are a separate backup
type and stay as they are. App and DB restore points should later be offered
as a pair, but that's out of scope here.

## 5. Security and safety

- **Tenancy check (blocking).** Only return or accept restore points whose
  source namespace equals the org's namespace. Longhorn keeps the original
  `namespace`/`pvcName` in the volume's `KubernetesStatus` label (confirm on
  a live cluster). Also record the PVC to Longhorn-volume mapping in
  `app_instance.settings` and `org_backups.settings` at backup time, so DR
  still works if labels are missing. Reject on mismatch; never trust an ID or
  URL from the request body. Take only an opaque restore-point ID, resolve it
  server-side.
- Admin-only initially, using the same route group as
  `Admin\Organizations\BackupRestore`. Audit via `Log::info(..., ['organization_id'])`
  as the rest of the backup code does.
- Validate names (`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`), sizes (digits only), and
  replica counts (1 to 10). Build manifests as arrays and `json_encode`, never
  string-interpolated YAML.
- PV uses `Retain` and a pre-bound `claimRef`.
- Idempotency: every `apply()` step is create-if-absent and re-entrant, so a
  retried queue job can't double-restore. The restore name includes the
  `OrgBackup` id.
- **RBAC.** `docs/k8s-rbac-sample.yaml` has no PVC, PV, or Longhorn rules
  today. Add an *optional*, separate `kumulicp-restorer` ClusterRole:
  `get/list/watch` on `backups`, `backupvolumes`, `backuptargets`, and
  `get/list/create/delete` on `volumes` (group `longhorn.io`, only in
  `longhorn-system` via a namespaced Role); `persistentvolumes` (create/get/
  list/delete); `persistentvolumeclaims` (get/list/create/delete); and, for
  phase 6, `volumesnapshots`/`volumesnapshotcontents`. Keep it separate so
  installs that don't want restore keep least privilege.

## 6. Configuration

Server settings (JSON `settings` on the `helm_k8s` Server, read with
`Server::setting()`): `restore_provider`, `longhorn_namespace`
(default `longhorn-system`), `restore_replicas` (3), `restore_storage_class`
(falls back to the existing `storage_class`), `restore_fs_type` (`ext4`).
Document them in the `helm_k8s` profile description and `en`/`es` lang files.

## 7. UI

Extend `resources/js/Pages/Admin/Organizations/Backups/BackupsList.vue` and
`BackupRestore` controller: a "Volume restore points" table per app, a
**Preview manifests** action (renders the `plan()` YAML), and a **Restore**
action with a confirm modal naming the target claim. New routes in
`routes/web.php`; strings in `resources/lang/{en,es}`.

## 8. Testing

- Unit (no cluster): golden-file tests for the three Longhorn manifests,
  including one asserting the exact `Volume` YAML from the original request.
  Cover size passthrough as a string, name validation, and the tenancy filter.
- Feature: fake `kubectl` with `Process::fake()` (`KubectlCli` runs through
  the `Process` facade) for `restorePoints()`, `apply()` ordering and
  idempotency, controller authorization, and cross-org rejection. Follow
  `tests/Support/ServerManagers/FakeServerManager.php`.
- Manual: Longhorn needs iSCSI/`open-iscsi` on the node, which the compose
  `k3s` service won't have. Add a runbook to `docs/testing-helm-driver.md`
  for a real Longhorn cluster with a MinIO backup target.

## 9. Phases

0. **Spike (≈1–2 days, on a real Longhorn).** Confirm CRD field names, the
   `KubernetesStatus` label, the restore-complete signal, and BackupTarget
   resync on a fresh cluster. Everything below depends on these. Output: fixtures.
1. **Core.** Contract, registry, facade, DTOs, manifest builders, `plan()` /
   dry-run, unit tests. Fix `BackupService::driverExists`.
2. **Discovery.** `KubectlCli::list/wait`, `restorePoints()`, tenancy check,
   record PVC to volume mapping at backup time.
3. **Execution.** `apply()` and `status()`, `OrgBackup` integration, async polling.
4. **App wiring.** Unify existing-claim handling across charts, orchestrate
   scale-down / restore / upgrade.
5. **UI, RBAC sample, config, i18n, docs.**
6. **More providers.** `CsiSnapshotRestoreProvider` (VolumeSnapshotContent with
   a Longhorn `bk://` handle, or any CSI driver; PVC with `dataSource`), then
   Velero. Full-cluster DR runbook: reinstall Longhorn, set backup target,
   force sync, restore per org.

## 10. Decisions for you

I assumed the following. Tell me if any is wrong:

- `helm_k8s` driver only for now; Rancher later.
- Admin-triggered restore only (no customer self-service).
- Restore to a new PVC name by default, with in-place name reuse only for
  empty-cluster DR.
- Web/app data first (Nextcloud, WordPress, CiviCRM); DB restore pairing later.
