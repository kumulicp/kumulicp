# Plan: volumes, kept simple

Status: proposed. This replaces the first version of the plan. Slice 0 (the tests that pin what each
chart gives its volumes) is merged and is the safety net for everything here; nothing else is built on
`main`.

## What changed from the first plan, and why

The first plan kept every instance working while its settings were being moved, so it grew a layer to read
the old places (`LegacyVolumeKeys`), a map from each volume to each chart's Helm keys (`VolumeSpec::map`,
`blob`, `only_set`, `blank_claim`, `access_mode_as`), claim modes, a claim/discovered split and a
`workloads.<workload>.volumes.<volume>` nesting. All of that exists to avoid one thing: migrating the data.

This plan migrates the data once, up front, and then there is nothing to bridge:

- A profile declares its volumes in a `$volumes` array next to `$configurations`.
- The same volume name is used by the plan's settings, the instance's settings, the form and the chart.
- A chart spells its own Helm keys from the volume. No map, no blob, no legacy reader.

## The shape

### In the profile

```php
// NextcloudProfile, beside $configurations
protected $volumes = [
    'data' => [
        'label' => 'admin.volumes.data',
        'size' => 'quota',                  // follows the subscription's storage quota
        'enabled' => false,                 // the defaults of the fields a plan or an instance can change
        'storage_class' => '',
        'access_mode' => 'ReadWriteOnce',
    ],
    'redis-master' => ['label' => 'admin.volumes.redis_master', 'optional' => true, 'size' => 8, 'enabled' => false, 'storage_class' => ''],
    'redis-replica' => ['label' => 'admin.volumes.redis_replica', 'optional' => true, 'size' => 8, 'enabled' => false, 'storage_class' => '', 'claim' => false],
    'mariadb' => ['label' => 'admin.volumes.mariadb', 'optional' => true, 'size' => 8, 'enabled' => false, 'storage_class' => '', 'access_mode' => 'ReadWriteOnce'],
];
```

- `size` is `'quota'` for the volume that holds the customer's files, or a number of Gi for a volume with
  a size of its own.
- `optional` means the app can do without it: it is off until `enabled` is true.
- `claim => false` means the volume can't be given a claim that exists (a StatefulSet's replicas make their
  own), so the move form offers only a new one.
- `AppProfile` has a default `$volumes` with one `data` volume sized by the quota, so every app has one,
  plugins included.

### In the settings

The same name and the same fields, in the plan's settings and in the instance's:

```
plan.settings.volumes.<name>.{enabled, size, storage_class, access_mode}
instance.settings.volumes.<name>.{enabled, size, storage_class, access_mode, claim}
```

A field resolves the way `configuration()` does: **the instance's, else the plan's, else the profile's.**
`claim` is the instance's alone: a claim is a fact about one instance's volume, never a default.
For a `quota` volume, the instance's `size` is the largest size it has been given: it only grows.

### In the chart

```php
$volumes = Volumes::for($this->app_instance);
$data = $volumes->get('data');
$redis_master = $volumes->get('redis-master');

'persistence' => [
    'enabled' => $data->enabled(),
    'size' => $data->size().'Gi',
    'storageClass' => $data->storageClass(),
    'accessMode' => $data->accessMode(),
    'existingClaim' => $data->claim(),
],
'redis' => ['master' => ['persistence' => [
    'enabled' => $redis_master->enabled(),
    'size' => $redis_master->size().'Gi',
    'storageClass' => $redis_master->storageClass(),
]]],
```

`Volumes::for($instance)` finds the profile through `Application::profileFor($instance)`, so an app on an
added catalog chart gets that chart's volumes. `size()` is the number of Gi the volume is made with: for a
`quota` volume it asks the subscription and keeps any growth. A chart given its release's storage (a shared
app's) passes it in.

Each chart writes the four or five keys itself. They differ between charts (`accessMode` or `accessModes`,
a list or a string, a claim that is `null` or `''`), and that is clearer in the chart than in a table
somewhere else. Where several charts really are the same shape, a small `persistence($volume)` helper on
`HelmChart` can return it; that is a convenience, not a framework.

The one place a path map stays is `HelmForgeChart`, which has no hand-written chart and writes to the keys
the catalog detected. That is where it already is.

### What is read where, and nowhere else

| What | Where |
|---|---|
| Which volumes an app has, their defaults, whether they take a claim | the profile's `$volumes` |
| What a plan gives a volume | `plan.settings.volumes.<name>` |
| What an instance has chosen for a volume | `instance.settings.volumes.<name>` |
| Reading and writing those | `Volumes` and `Volume`, and nothing else spells the paths |

## What goes away

- `persistence-*` entries in `$configurations` (Nextcloud, WordPress, CiviCRM), and the `redis` and
  `mariadb` blobs' persistence keys.
- `AppProfile::storageHandover()`, its five overrides and `StorageHandover`.
- `override.pvc.*`, `existing_claim` and `chart_storage` in instance settings.
- The claim a job finds on the cluster is no longer written to the settings (see "Decisions").
- Dead configuration found while reading: `persistence-existingClaim` (declared by Nextcloud, WordPress and
  CiviCRM, read by no chart), `persistence-numberOfReplicas` (declared by Nextcloud and CiviCRM, read by
  nothing) and CiviCRM's `redis` (its chart never reads it).
- Not built, from the first plan: the `workloads` level, claim modes, `LegacyVolumeKeys`, `VolumeSpec::map`,
  the blob reader, `only_set`, `Volume::on()` and the catalog detector recording several volumes.

## The migration

One data migration, and a backup of every chart's values to prove it changed nothing.

### What moves

Plan settings (`app_plans.settings`) and instance settings (`app_instances.settings`) both hold
`configurations.*`; the same keys move in both.

| From | To |
|---|---|
| `configurations.persistence-enabled` | `volumes.data.enabled` |
| `configurations.persistence-storageClass` | `volumes.data.storage_class` |
| `configurations.persistence-accessMode` (Nextcloud) or `persistence-accessModes` (WordPress, CiviCRM; a list or a string) | `volumes.data.access_mode` (the first mode) |
| `configurations.redis` `master.persistence.{enabled,size,storageClass}` | `volumes.redis-master.{enabled,size,storage_class}`, and out of the blob |
| `configurations.redis` `replica.persistence.*` | `volumes.redis-replica.*`, and out of the blob |
| `configurations.mariadb` `primary.persistence.*` (Nextcloud's includes `existingClaim` and `accessMode`) | `volumes.mariadb.*`, and out of the blob |
| instance `existing_claim` (Nextcloud) | `volumes.data.claim` |
| instance `override.pvc.name` (WordPress, catalog) | `volumes.data.claim` |
| instance `override.pvc.name` (CiviCRM) | `volumes.data.claim` only when `override.pvc.override` is true; otherwise it was a name a job found, and is dropped |
| instance `chart_storage` | `volumes.data.size` |

Rules that keep it honest:

- **Only what differs is written.** Activation copied every persistent default into each instance's
  `configurations`, so most instance keys equal the plan's or the profile's. A value that equals what the
  instance would get anyway is not written, so it keeps following the plan.
- **Which chart's rules apply** is decided by the chart the instance runs (`profileFor()`, then its chart
  class), not by its application: an app with an added catalog chart has two layouts.
- **Sizes are whole Gi.** `8Gi` becomes 8. A size that is not (`500Mi`, `1.5Gi`) is rounded up and listed
  in the dry run.
- **Only one access mode is kept.** A WordPress list with several is cut to the first and listed.
- **A claim in a plan's settings** (the blobs have an `existingClaim` key) is listed and dropped: a claim
  is one instance's, and a plan can't hold one.
- The migration **copies, it does not delete**. The old keys stay until a later migration removes them, so
  rolling the code back finds its settings as they were.
- Plugins' apps get the same treatment for `chart_storage` (it becomes `volumes.data.size`), since
  `appStorage()` is rewritten on the same store.

### Proof it changed nothing

Two checks, one by a machine and one by a person, both from the same files.

**A backup of every chart's values, taken before** (`backup:charts`, below), and again after. Each is a zip
of YAML files an admin can download.

```
php artisan backup:charts                      # on the release BEFORE: the "before" archive
php artisan volumes:migrate --dry-run          # what would move, and every instance listed above
php artisan volumes:migrate
php artisan volumes:verify <before archive>    # renders every instance again and compares, file by file
php artisan backup:charts                      # on the release AFTER: the "after" archive
```

- **`volumes:verify` reads the before archive**, renders each instance's chart again the same way, and lists
  every file that differs. A difference is a stop. There is no separate snapshot command: the backup is the
  snapshot, so a person and the command look at the same thing.
- **The visual audit**: an admin downloads the before archive and compares a file with the app's Helm values
  page (`Admin\Organizations\Applications@helmValues`), which shows the chart's values today; after the
  release they compare the page with the file. They are the same render, so they should be identical.

Both check the whole chain and not the migration's own idea of it. The Slice 0 tests do the same for the
shapes a test can build.

## Chart values backup (its own PR, to `main`, first)

`backup:charts` is built and merged **before** any of the volume work, so a "before" archive can be taken
from the release that is running now. It is a feature on its own: a record of what every app was deployed
with, useful before any upgrade or chart change, and not only for this migration.

**What it does**

- For every app instance that has a chart (not deactivated, and with a web server), render each chart the
  way the Helm values page does (`AppInstanceService::charts()`, then `Redactor::redact()`), and write it as
  YAML.
- Write one zip to the private `backups` disk (`config('filesystems.app_backup')`), in a `chart-values/`
  folder, named `chart-values-<date and time>.zip`. Inside:
  `<organization slug>/<app instance name>/<chart name>.yaml`, and a `manifest.json` with when it was taken,
  the core version, and for each file the instance, app, version, chart and its sha256, and any instance that
  could not be rendered, with the reason (`SafeErrorMessage`). One that fails does not stop the others.
- Keep the last N archives (`--keep`, default 8) and delete older ones, as `backup:clean` does for the others.

**How admins use it**

- An admin page lists the archives, with **Download**, **Create now** (a queued job) and **Delete**, behind the
  existing `can:admin` gate. Downloads are streamed from the disk and never served from a public path.
- The command is also scheduled (weekly), with the others in `routes/console.php`.

**Things it has to get right**

- **It must not change anything.** Charts write small bookkeeping settings when they are built
  (`override.chart.values.fullNameOverride`, the storage ratchet). `PlanValuesPreview` renders inside a
  transaction that is always rolled back for that reason; the backup does the same, for each instance. The
  Helm values page does not today, so opening it can write; that is not changed here, but the same
  rendering method can serve both, and then it would.
- **Secrets stay masked.** The files are what the page shows, through the same `Redactor`, so the archive is
  safe to download. A test builds an instance with known passwords and checks none is in the archive. The
  cost is that a changed password is not seen by the audit; for volumes that does not matter.
- **The name.** `backup:charts` puts it beside Spatie's `backup:run`, `backup:clean` and `backup:list`, which
  are scheduled in `routes/console.php` and back up the app itself. That is fine, but the page and the
  help text should say this one is a record of rendered values, not something to restore from.
- **Same render as the page.** The page shows `values()`; a release deploys `valuesWithAdditionalConfigs()`
  (the plan's extra configurations, the kumulicp labels and the version's image overrides as well). For the
  audit to be exact the archive and the page should show the same, so both should use one method, which is an
  open question below.

**Tests**: a command test (one archive, the layout, the manifest, a chart that fails), a test that nothing
in the database changed, one that no secret is in the archive, retention, and the page (list, create,
download, delete, a non-admin refused once for the whole admin group).

## Build order

0. **`backup:charts` and its admin page**, a separate PR to `main`, merged and released first, and run
   by hand on the release that is live before the migration release goes out.
1. **The store.** `AppProfile::$volumes` and its default, `Volumes`, `Volume` (`enabled()`, `size()`,
   `storageClass()`, `accessMode()`, `claim()`), `assign()` / `restore()` over `SettingsSnapshot`, and
   `VolumeRules`. No chart uses it yet.
2. **The migration and `volumes:migrate`, `volumes:verify`.** With tests for every row above, and for the chart a
   catalog app runs.
3. **The charts switch.** Nextcloud, WordPress, CiviCRM, the catalog chart and the job charts read
   `$volume->…()`; `appStorage()` is rewritten on the store; the profiles declare `$volumes` and lose their
   `persistence-*` configurations. **Slices 2 and 3 ship in one release**, since the code stops reading the
   old places in the same release that fills the new ones.
4. **Replace `StorageHandover`.** The move, `AwaitVolume`, `SwitchServer` and the form use `Volumes`; a
   handover for each volume the app has; the old class and `storageHandover()` go.
5. **The forms.** A Volumes card on the plan page and a Volumes panel on the instance page, both built
   from the profile's `$volumes`, in place of `persistence-*` configurations and the JSON textarea for
   volumes.
6. **The generic chart.** Its volumes are a list the plan defines (`persistence-N-name`, `mountPath`, ...),
   which does not fit a profile's fixed `$volumes`. It keeps its array configurations for the list, reads each
   volume's claim, class, mode and size from `volumes.<item name>`, and has its own migration.
7. **Cleanup.** A later release deletes the old keys, and `ReleaseAspect` stops excluding `override.pvc`.

The release fingerprint (`ReleaseAspect`) includes `volumes` from slice 3, with a re-stamp migration as
`restamp_fingerprints_for_version_images` did, so a changed claim shows the app as out of date.

## Decisions I made, which you can overrule

- **A flat `volumes.<name>`**, not `workloads.<workload>.volumes.<name>`. Per-workload resources or replicas
  are not planned; if they are, they get their own key then.
- **No `discovered` claim.** A job that needs the claim uses the chosen one, else asks the cluster, and does
  not write what it finds. That removes a write from a release and the fingerprint exception for it, and means
  a name found on the cluster can never be given back to the chart as a claim that exists. It costs a cluster
  call per job. (The lookup still takes the first claim the release has, whichever workload it is for; a
  proper fix needs each chart's own claim name.)
- **One access mode, a string**, in the settings; the chart makes a list where its chart takes one.
- **A volume either takes a claim or does not** (`claim => false`). Whether a StatefulSet adopts a claim
  restored under its own name is not verified, so it is not offered.
- **The generic chart goes last**, because its volume list is plan data.

## The quirks Slice 0 pins, and what each does here

Each stays, or changes on purpose in the slice that changes it, naming the test.

- Nextcloud gives the chart `existingClaim => null`, WordPress and CiviCRM `''`. Kept, as a line in each chart.
- CiviCRM stores one access mode as a string and its chart wraps it in a list; WordPress stores a list.
  Becomes one string in the settings, wrapped by each chart that needs a list.
- The catalog chart is given only what it has a key for, and never an access mode. Kept, in `HelmForgeChart`.
- A claim a job found is no longer given to the next release (changed on purpose, with the `discovered` decision).

## Out of scope

Resources, ingress, image and replicas are the same shape (a profile array, plan and instance settings by
the same name, a chart that spells its keys) and can follow once this works. A volume backup driver,
volume expansion beyond the quota's growth, moving an instance to a plan on another chart (a separate plan),
and an organization-facing settings page.

## Open questions

0. **What the archive and the page show.** Today the page shows `values()`. A release deploys
   `valuesWithAdditionalConfigs()`, which also has the plan's extra configurations, the labels and the image
   overrides. Make both show the deployed values (changes the page), or keep both on `values()` (the audit
   then does not cover what is added on top; volumes are in `values()`, so it covers them).
0b. **Schedule and retention.** Weekly, keeping the last 8 is a guess. Daily would be cheap (the files are
   small) but is more to look through.

1. **Release unit.** Slices 2 and 3 ship together and there is no fallback, so an instance the migration
   missed loses its claim and its chart would make a new, empty volume. The chart values backup and `volumes:verify` are
   the guard. Is a run against a copy of production data before the release acceptable, or do you want the
   old keys read for one release as well?
2. **Plan-level `claim`.** Not allowed here (a claim belongs to one instance). Confirm.
3. **Catalog charts' volumes.** One volume (`data`), from what the detector found for the primary. Several
   detected volumes would each need a path in `HelmForgeChart`; not planned unless a catalog chart needs it.
4. **Redis's claim key.** `master.persistence.existingClaim` is not in Nextcloud's `redis` configuration, only
   MariaDB's is. Check it against the subchart before the move form offers a claim for Redis.
