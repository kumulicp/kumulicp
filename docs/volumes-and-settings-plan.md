# Plan: standard chart values and a standard app-settings framework

Status: proposed. Nothing here is built. It records what the code does today (checked
against `main` at `4e5c582`, no cluster and no test run), what to change, and an order to
build it in.

## Why

Two goals, one piece of work:

1. **One method per common Helm value group**, volumes first. Every chart class spells the
   same things (persistence, resources, ingress, image, replicas) by hand. The volume spelling
   differs so much between charts that `App\Support\StorageHandover` needed a per-profile
   map (`AppProfile::storageHandover()`) just to find where each chart keeps its claim.
2. **Settings an admin can read.** The instance settings page
   (`resources/js/Pages/Admin/Organizations/Apps/AppEdit.vue`) is one JSON textarea over
   `app_instances.settings`, saved whole by `Admin\Organizations\Applications@update`
   (`$app->settings = json_decode(...)`). The target is a real form, laid out in groups.

The two meet in one place: if every chart reads a volume from the **same settings location**,
there is nothing left for `StorageHandover` to map, and a form can be generated from the same
definitions the charts use.

## Where we are

### Volume settings, per chart

| App / chart | Claim | Access mode | Storage class | Size |
|---|---|---|---|---|
| Nextcloud | `existing_claim` | `configurations.persistence-accessMode` (string) | `configurations.persistence-storageClass` | `chart_storage` |
| WordPress | `override.pvc.name` | `configurations.persistence-accessModes` (list) | same | same |
| CiviCRM | `override.pvc.name` **and** `override.pvc.override = true` | `persistence-accessModes`, wrapped in a list by the chart | same | same |
| HelmForge catalog | `override.pvc.name` | none | same | same |
| Generic | `configurations.persistence-<n>-existingClaim` (one entry per volume) | `persistence-<n>-accessMode` | `persistence-<n>-storageClass` | `persistence-<n>-size`, else `chart_storage` |

Each chart then turns those into its own Helm keys (`persistence.existingClaim`,
`persistence.accessMode` / `accessModes`, `persistence.persistentVolumeClaims[]`, ...). The
rest of the code that touches the volume:

- `HelmChart::appStorage()` writes `chart_storage` as a **ratchet** (it only ever grows, to
  the subscription's `storage_quota`).
- `JobChart::persistentVolumeClaim()` returns `override.pvc.name`, and if that is empty
  **looks the claim up on the cluster and writes it back** to `override.pvc.name`.
  `NextcloudJobChart` has its own variant (`existing_claim`, else `<fullname>-nextcloud`).
- `ReleaseAspect` fingerprints `configurations` and `override` but **excludes `override.pvc`**
  ("written back by the release itself"), and does not fingerprint `existing_claim` or
  `chart_storage` at all.
- `persistence-existingClaim` is declared as a configuration in the Nextcloud, WordPress and
  CiviCRM profiles, but none of those charts read it (they read the keys in the table).
  It looks like dead configuration; confirm before removing.
- `StorageHandover` (rules, `apply()`, `restore()`) is used by `ApplicationWebMove`,
  `AwaitVolume`, `SwitchServer`, `Applications@move_web`, the move form, and
  `tests/Feature/Applications/StorageHandoverTest.php`. `AppProfile::storageHandover()` is
  overridden by five profiles and by the `DemoAppProfile` test hook.

### Several charts for one app (added in #233, after this plan was first written)

An application can now be deployed from more than one chart (`app_charts`; see
`docs/chart-catalog.md`, "Several charts for one app"). The registered Nextcloud chart and a
HelmForge catalog chart of the same app can both exist, each with its own version groups, and
a plan picks one of them when it is created. What that changes for this plan:

- **The chart, not the app, decides the settings layout.** `Application::profileFor($instance
  | $plan | $chart)` gives the profile of the chart the install runs from: the version's
  chart, else the chart of the group its plan picked (`chartFor()`). The default chart uses the
  app's own profile (`NextcloudProfile` + `NextcloudChart`); a catalog chart added to a
  registered app uses a `HelmForgeProfile` built from **the chart's** `catalog`, and
  `HelmForgeChart::catalog()` reads the chart row for it. So a Nextcloud app instance on the
  HelmForge chart keeps its claim in `override.pvc.name`, not `existing_claim`, and has no
  access-mode setting. `AppInstanceService::configuration()`, `arrayConfiguration()` and
  `StorageHandover::map()` already moved to `profileFor()`.
- **A legacy key cannot be read from the application.** Anything that picks a volume layout
  from `$instance->application` (the migration table below, a settings form, a default claim
  name) is wrong for the instances that run an added chart. They must go through
  `profileFor()`, or the chart row.
- **Catalog data lives in two places.** `applications.catalog` for a default catalog chart,
  `app_charts.catalog` for an added one. The sync (`ChartCatalogSync::attach()` and the daily
  run) now adds a chart of the source to every registered app of the same name, so a
  hand-written app such as Nextcloud or WordPress can get a HelmForge chart whose volumes are
  detected, not declared. Volume detection therefore matters for apps that already have a
  hand-written layout.
- **Constructing a chart class is pure** (`PureConstructorsTest` pins this for actions; the
  charts take only an organization and an instance), so a chart can be instantiated outside a
  release (in a move form, a settings page, a migration) to read what volumes it declares.
- **An instance's chart is fixed once the plan is** ("its group, and so its chart, is fixed
  afterwards"), but an instance can be given another plan. Whether that can change its chart
  is an open question below.
- **Precedents to follow.** #233's `move_chart_identity_off_versions` migration and
  `ChartIdentityMigrationTest` moved chart identity off `app_versions.settings`; #281's
  `remove_update_recommended_from_app_instances_settings` removed a settings key from
  instances in chunks with a pure-DB migration. The data migration below should look like
  those and be tested the same way.

### Charts with more than one workload

Every chart is treated as having **one** data volume today. Charts that deploy several
workloads (a Deployment plus StatefulSets, from subcharts or sidecars) have volumes the
code does not model:

| Chart | Other workloads with their own volume | How it is handled today |
|---|---|---|
| Nextcloud | Redis master and replica (`redis.master.persistence`, `redis.replica.persistence`) | A `redis` YAML configuration holds them as an opaque blob, `persistence.enabled: false` by default. No size, class or claim control, not part of a move. |
| WordPress, CiviCRM | The MariaDB subchart (`mariadb.primary.persistence`) | A `mariadb` JSON configuration, `enabled: false` by default (the database is an external server). Same limits. |
| Nextcloud | Imaginary, the cron sidecar | No volume today. They are workloads that other settings (resources, images) already touch, so they are named here for completeness. |
| Generic | One pod, several PVCs (`persistence-<n>-*`) | Modelled, as items of one array configuration; handover is refused when there is more than one. |
| HelmForge catalog | Whatever the chart has | `ChartConventionDetector::persistence()` records **one** volume: `persistence.<volume>` is picked by the `persistence_volume` override (`config/catalog.php`, used for `paperless-ngx`), else the first enabled, else the first. The others keep the chart's defaults and cannot be sized, classed or handed a claim. |

Consequences found while reading the code:

- `appStorage()` / `storage_quota` is **one** number, written to the one volume the chart
  treats as the app's. Secondary volumes have no size policy at all.
- `JobChart::persistentVolumeClaim()` writes back the **first** `PersistentVolumeClaim` in the
  release's resources to `override.pvc.name`. If a secondary volume is ever enabled, the first
  one found could be Redis's or MariaDB's, and the next WordPress or HelmForge release would be
  given it as `existingClaim`. Today it is mostly hidden because those volumes are disabled.
- A web move restores and hands over one volume. An enabled Redis or MariaDB volume would not
  move with the app, and nothing says so.
- StatefulSet volumes behave differently from a Deployment's PVC: replicas get their own claims
  from `volumeClaimTemplates` (`<template>-<pod>-<ordinal>`), and many subcharts accept
  `existingClaim` only for a single replica. A single "claim name" does not describe them.
  (This is Kubernetes behaviour to confirm on a cluster, not something the repo proves.)

### The settings bag

`app_instances.settings` is one JSON column (`'settings' => 'array'`) holding several
different things:

| Kind | Examples | Who writes it |
|---|---|---|
| Admin/plan configuration | `configurations.*` (persistent ones), `override.<config>` | admin, plan |
| Chart overrides and caches | `override.chart.*`, `override.pvc.*` | the release code |
| Volume | `existing_claim`, `chart_storage`, `override.pvc.*` | admin, moves, release code |
| Runtime state | `storage_quota`, `expand_storage`, `quiesced_for_move`, `availability`, `maintenance`, `project_id` | the system |
| Integration state | `sso`, `sso_client_id`, `sso_client_secret`, `features.*` | SSO and feature actions |

`AppInstanceService::configuration()` resolves a value as: `override.<name>` (when the
configuration is not persistent) > `configurations.<name>` (when persistent or personalized)
> the plan's `configurations.<name>` > the profile's default. Profiles already describe
their configurations as data (`name`, `type`, `default`, `persistent`, `validations`), and
the plan Configurations screen (`PlanConfigurations.vue`, `arrayConfigs.ts`) already renders
them by type. So **a schema half-exists**; what is missing is grouping, labels, sources, and
a place for the settings that are not configurations.

Saving the textarea replaces the whole column, so an admin who opens the page, waits while a
task writes `sso` or `storage_quota`, and then saves puts the old values back.

## Design

### 1. A volume belongs to a workload; one settings location for all of them

Volumes are addressed by **workload and volume name**. A workload is one Deployment,
StatefulSet or sidecar group the chart deploys; it owns zero or more volumes. Instance
settings get one namespace:

```
settings.workloads.<workload>.volumes.<volume> = {
    claim:         "name of an existing PVC" | null,   // explicit, chosen by a person or a move
    discovered:    "name the cluster reported" | null, // cache written by the release; never fed to the chart
    size:          Gi (int) | null,
    storage_class: string | null,
    access_mode:   string | null,                      // always one string; the chart makes a list if it needs one
    enabled:       bool | null,                        // for volumes the chart can run without (Redis, MariaDB)
}
```

- **The primary volume** is `app` / `data` for every chart: the one that holds the customer's
  files and follows the subscription's storage quota. Single-volume charts have only this.
  The generic chart's items become `app` / `<item name>`, with the item flagged primary
  when it is the one the quota applies to (the first, unless the plan says otherwise).
- **Secondary volumes** are the other workloads' (`redis-master` / `data`, `mariadb` /
  `data`, ...). The workload names are chosen by the chart class and are stable: they are
  settings keys, so renaming one is a migration.
- The workload key also gives a home for the other per-workload values later (resources,
  replicas, image), which Redis, Imaginary and the cron sidecar already need. Only
  `volumes` is in scope here; the key is chosen so the rest can follow without moving it.
- **`claim` and `discovered` are separate on purpose.** Today `override.pvc.name` is both:
  the handover writes it, and `JobChart` caches the cluster's answer in it, and WordPress and
  HelmForge feed it back to the chart as `existingClaim`. That mix is why
  `ReleaseAspect` has to exclude `override.pvc`. After the split, only `claim` reaches the
  chart; `discovered` is read only by jobs, and the `override.pvc` exclusion goes away.
- Defaults still come from the plan and the profile through the existing `persistence-*`
  configurations, with the name normalised to **`persistence-accessMode`** (one string) in
  every profile. Resolution for each field: the instance's `workloads.*.volumes.*` > the plan >
  the profile's default, the same order `configuration()` uses. Secondary volumes use the
  same names with the workload as a prefix (`persistence-redis-master-size`); array
  configurations (`persistence-<n>-*`) stay the way the generic chart's plan defines its
  volumes.
- The plan decides **which volumes exist** for the generic chart (its items); for hand-written
  charts the chart class does (section 2). The instance only holds what is specific to it: a
  claim, a grown size, an overridden class, a secondary volume turned on.

#### Sizing

Today `appStorage()` is the one size for the one volume. With several volumes each declares
a **size policy**:

| Policy | Meaning | Used by |
|---|---|---|
| `quota` | follows `storage_quota` through the existing ratchet (`chart_storage`, which only grows) | the primary volume; exactly one per instance |
| `fixed` | the instance's `size`, else the plan's, else the volume's declared default; never follows the quota | Redis, MariaDB, any secondary volume |

Secondary volumes are not billed or counted in `storage_quota` (see open questions).

#### How a claim is handed over

A volume declares a **claim mode**, because the three kinds of volume need different things:

| Claim mode | The chart takes | Handover |
|---|---|---|
| `existing_claim` | one claim name at a values path (Deployment PVCs, single-replica subcharts) | write `claim`; the chart skips creating its own |
| `by_name` | no name: a StatefulSet makes `<template>-<pod>-<ordinal>` per replica | the restored claim must be created with the name the chart would make (`defaultClaimName($workload, $volume, $ordinal)`); no `claim` is written |
| `none` | nothing (a chart that cannot be given or adopt a claim) | only a new volume is offered |

A volume with more than one replica is `by_name` or `none`, never `existing_claim`; the
form says why. Whether a StatefulSet adopts a claim that already exists under its template
name (the usual Kubernetes behaviour) is to be confirmed on a cluster before `by_name` is
offered in the move form.

#### The store

The location is **the same whichever chart the instance runs**, which is the point: an
instance that changes chart keeps its primary volume's `claim`, `size`, class and mode,
because `app/data` means the same on every chart. Secondary volumes are chart-specific by
name; values stored for a workload the current chart does not declare are ignored, not
deleted, so going back finds them.

A small class owns all reads and writes, so no other code spells these paths. It finds what
an instance declares through `Application::profileFor($instance)`, never the application's
own profile:

```php
// App\Support\Volumes\Volume (value object) and App\Support\Volumes\Volumes (the store)
Volumes::for($appInstance)->all();                       // every volume the chart declares, resolved
Volumes::for($appInstance)->primary();                   // app/data
Volumes::for($appInstance)->get('redis-master', 'data'); // resolved Volume: enabled, size, storageClass, accessMode, claim, claimMode
Volumes::for($appInstance)->claimName('app', 'data');    // claim ?: discovered ?: the chart's default claim name
Volumes::for($appInstance)->assign($workload, $volume, $values);  // writes the fields, returns the previous values
Volumes::for($appInstance)->restore($before);
```

`assign()` / `restore()` are the part of `StorageHandover` that is not chart-specific. They
write the fields and return what was there (`['path' => ['value' => ...] | null]`), which is
what `SwitchServer` already stores on the task, so one snapshot covers every volume of the
app. That snapshot helper is not about volumes, so it becomes a small generic
`SettingsSnapshot` (apply a set of dot-path writes, return what was there, put it back),
which the SSO move can use too.

### 2. One chart-side method per value group

`HelmChart` gets one method per group. Each chart class **declares its volumes once**, and
the base class writes all of them; the chart never writes persistence values itself:

```php
// in a chart class
public function volumes(): array
{
    return [
        'app/data' => [
            'primary' => true, 'size' => 'quota', 'claim_mode' => 'existing_claim',
            'map' => ['path' => 'persistence', 'enabled' => 'enabled', 'size' => 'size',
                      'storage_class' => 'storageClass', 'access_mode' => 'accessMode',
                      'claim' => 'existingClaim'],
        ],
        'redis-master/data' => [
            'size' => 'fixed', 'default_size' => 1, 'claim_mode' => 'existing_claim', 'optional' => true,
            'map' => ['path' => 'redis.master.persistence', 'enabled' => 'enabled', 'size' => 'size',
                      'storage_class' => 'storageClass', 'claim' => 'existingClaim'],
        ],
        'redis-replica/data' => [
            'size' => 'fixed', 'default_size' => 1, 'claim_mode' => 'by_name', 'optional' => true,
            'map' => ['path' => 'redis.replica.persistence', 'enabled' => 'enabled', 'size' => 'size',
                      'storage_class' => 'storageClass'],
        ],
    ];
}

// in values()
$values = array_replace_recursive($values, $this->volumeValues());   // one call, every declared volume
```

- `volumeValues()` resolves each declared volume through `Volumes`, writes it at its `map`
  (`size` as `"<n>Gi"`, `access_mode` as a string or, with `'as' => 'list'`, a list, a
  `claim_flag` for CiviCRM's `override: true`), and writes **only what the volume has**, so a
  blank option keeps the chart's own default (the rule `HelmForgeChart` already follows).
- A **default map** covers the common bitnami-style layout
  (`persistence.{enabled,size,storageClass,accessModes,existingClaim}`), so a chart with one
  ordinary volume declares nothing beyond `'app/data' => ['primary' => true]`.
- A secondary volume with `'optional' => true` is off unless `enabled` is set, which replaces
  the opaque `redis` and `mariadb` configuration blobs' persistence sub-keys. What else those
  blobs hold (image tags, replica counts, passwords) is left in them for now; the volume
  resolution is overlaid on top and wins for the persistence keys, so an old blob value cannot
  fight the new setting.
- `ChartConventionDetector` already records, for each catalog chart, where it keeps `size`,
  `storage_class` and `existing_claim`. **That detected shape becomes the map format**, and
  `HelmForgeChart::setPersistence()` moves up into `HelmChart`. It is extended to record
  **every** volume it finds (see below), not one.
- The generic chart builds one declared volume per item (`app/<name>`), and keeps building the
  `persistentVolumeClaims` / `volumes` / `mounts` lists, which are genuinely chart-specific.
  Its per-item path is the map: it does not write `persistence.*` but the chart's own list shape.
- "Can this volume be given a claim that already exists?" is answered by `claim_mode`, not by
  a profile method. That replaces `StorageHandover::supports()` and `storageHandover() === null`.
- `defaultClaimName($workload, $volume, $ordinal = null)` (what the chart would call its own
  PVC, e.g. `<fullname>-nextcloud`, `<fullname>-<volume>`, `<template>-<pod>-<n>`) is declared
  once per chart and used by `Volumes::claimName()`. The job charts then stop looking the
  claim up on the cluster: `JobChart::persistentVolumeClaim()` and the `NextcloudJobChart`
  override become `Volumes::claimName('app', 'data')`. This removes the "first PVC found"
  lookup that is wrong once a chart has more than one (`WordpressJobChart` only calls the
  inherited method). Jobs mount the primary volume only, as they do now.

`Volumes::for($instance)` instantiates the instance's chart class (via `profileFor()`) only to
read `volumes()` and `defaultClaimName()`. For `HelmForgeChart` the declaration is built from
the chart's own stored convention (`app_charts.catalog` for an added chart,
`applications.catalog` for a default one), through the existing `catalog()` method, so a
Nextcloud app on a HelmForge chart declares the volumes the **detector** found, while a
Nextcloud app on its registered chart declares the ones `NextcloudChart` does.

#### Catalog charts

`ChartConventionDetector::persistence()` is changed from "pick one volume" to **record all**:
`persistence.<volume>` entries and the usual subchart shapes (`<sub>.persistence`,
`<sub>.primary.persistence`, `<sub>.master.persistence`), each with the paths it finds for
`enabled`, `size`, `storage_class`, `access_mode`, `existing_claim`. The primary is still
chosen by the `persistence_volume` override, else the first enabled, else the first; the rest
are secondary, `fixed`, and shown with the chart's own default size. The stored convention
keeps its existing `persistence` key (the primary, so nothing already synced breaks) and gains
`volumes`. It is written to **whichever row holds the catalog data**: `applications.catalog`
for a default catalog chart, `app_charts.catalog` for an added one, so `ChartCatalogSync`'s
sync and `attach()` both change. A re-sync fills it in; until then `HelmForgeChart` falls back
to `persistence`. The `config/catalog.php` `persistence_volume` override is keyed by chart
name, so it applies to a chart wherever it is attached.
A shape the detector does not recognise is left out and reported by
`catalog:sync --report`, not guessed at.

The same treatment, in later slices, for the blocks that are repeated almost verbatim:

| Method | Repeated today in |
|---|---|
| `resourcesValues($path)` | Nextcloud, WordPress, CiviCRM, Generic, HelmForge (`resources-requests/limits-cpu/memory`) |
| `ingressValues($style)` | WordPress, CiviCRM, Generic, HelmForge (the annotations/tls/enabled expression, including the "disabled app or maintenance mode turns ingress off" rule, is copied into each) |
| `imageValues($path)` | Nextcloud, WordPress, CiviCRM, Generic |
| `replicaValues($path)` | all (`replicaCount()` already exists; only the key differs) |

These are per-workload too (Redis, Imaginary and the cron sidecar take resources and an
image), so they take a workload name and write under that workload's `map` path. Because the
"ingress off while disabled or in maintenance" rule is a safety rule, it should live in
exactly one place.

### 3. A settings schema the UI can be built from

Extend what profiles already declare instead of adding a second system.

Everything in this section resolves its definitions through `profileFor($instance)`, so the
form shows the fields of the chart the instance actually runs, with that chart's name in the
header. Two instances of the same app on different charts have different forms (an instance on
a HelmForge chart has no access-mode field, for example), and a plan's configuration screen
follows `profileFor($plan)` the same way.

**Definition** (the existing configuration shape, plus optional fields):

```php
'persistence-storageClass' => [
    'name' => 'persistence-storageClass', 'type' => 'string', 'default' => '', 'persistent' => true,
    'validations' => 'nullable|string',
    // new, all optional
    'group' => 'storage', 'label' => 'admin.settings.storage_class.label', 'help' => '...',
    'options' => [...],            // enum choices (a type that already exists)
    'visible_if' => 'persistence-enabled',
    'applies_to' => ['release'],   // update aspect that must run for it to take effect
]
```

**Standard groups.** `App\Support\Settings\StandardSettings` returns ready-made definitions
for the groups every chart shares (storage, resources, ingress, image, replicas), so a profile
adds `...StandardSettings::storage()` instead of re-declaring five configurations. These are
the same names the chart methods in section 2 read, so a chart and its form cannot disagree.

**Registry of the non-configuration keys.** `App\Support\Settings\InstanceSettings` lists the
other top-level keys with an `owner`:

- `editable`: shown as a field (volumes, configurations).
- `system`: shown read-only in an "Advanced" panel with its current value, never written by
  the form (`storage_quota`, `expand_storage`, `quiesced_for_move`, `availability`,
  `maintenance`, `project_id`, `sso*`, `features`).
- Anything not in the registry is `unknown`: shown only in the raw JSON view.

**Save semantics.** The form posts only the editable fields; the server merges them into the
current `settings` inside a transaction with a row lock, so concurrent writes to system keys
are not lost. The raw JSON editor stays as an "Advanced (raw JSON)" tab for unknown keys and
repair, and is changed to save a **diff against the current settings** with a confirmation,
not a blind replace.

**Read model for the UI.** `InstanceSettingsSchema::for($appInstance)` returns groups of
fields, each with the effective value, where it comes from (`instance` / `plan` / `default`),
whether it is editable, and its rules. The controller passes it as an Inertia prop; the same
rules validate the save, so the front end has no rules of its own to drift.

**Front end.** One `SettingsForm.vue` with a field component per type (`string`, `int`,
`bool`, `enum`, `password`, `yaml`/`json`, `textarea`), grouped into cards, using the
existing Vuestic components and `$t()` labels. Each field shows a source badge and, for an
instance override, "reset to plan value". A saved change that needs a release shows the
existing update-drift notice (`SettingsAspect` / `ReleaseAspect`) rather than a new one. The
volume group is a dedicated panel, built from `Volumes::all()`: one section per workload, one
row per volume, so a chart with Redis or MariaDB volumes shows them under their own names.
Each row has claim (with a warning that this changes where data lives; hidden for a `by_name`
or `none` volume, with the reason), size (read-only when the policy is `quota`), class,
access mode, and an on/off switch for an `optional` volume. A single-volume chart shows one
row and no workload heading.

### 4. Moves use the volume methods

`StorageHandover` goes away:

| Today | After |
|---|---|
| `StorageHandover::rules()` / `rulesFor()` | `VolumeRules::rules($prefix)`, a plain rules array (claim, access mode, storage class, size; the same regexes) |
| `StorageHandover::map()`, `supports()`, `fields()` | each declared volume's `claim_mode` and map say whether it takes a claim and which fields it has |
| `StorageHandover::apply()` / `restore()` | `Volumes::assign()` / `restore()` over the generic snapshot helper, once per volume, into one snapshot |
| `AppProfile::storageHandover()` and five overrides | removed |
| `DemoAppProfile::$storage_handover` test hook | a `volumes()` declaration on `DemoHelmChart` |
| move form fields | the same four fields, **per volume**, from the volume definitions |

`ApplicationWebMove`, `AwaitVolume` and `SwitchServer` keep their shape; they call
`Volumes` instead.

**What the move does with several volumes.** The move task stores a handover per volume
(`storage` becomes a map keyed by `workload/volume`, replacing today's single set of values),
and the snapshot for rollback covers all of them:

- The form lists the volumes that **hold data**: the primary, and any secondary volume whose
  `enabled` is true. A volume that is off is an `emptyDir` in the chart; there is nothing to
  hand over and the form does not ask.
- For each listed volume the admin chooses `new` or `existing`, as now. `existing` needs an
  `existing_claim` volume; a `by_name` volume says which names the restore must produce
  (`defaultClaimName()` for each replica); a `none` volume can only start new, and the form
  says that the data in it will not come along.
- The wait-for-volume step (`AwaitVolume`) confirms **all** of them at once. Its message lists
  the volumes still to be restored, so a person restoring Redis and the app volume sees both.
- `blocker()` for the move refuses (and says why) when a volume that holds data cannot be
  handed over and the admin has not accepted starting it empty, so an enabled secondary volume
  can no longer be lost without anyone being told.
- Today a generic app with more than one volume is refused a handover entirely ("which of
  several the claim is for is not something to guess"). With volumes addressed by name there is
  nothing to guess, so that refusal goes away.

## Migration

### Data

A data migration (and an artisan command that runs the same code, with `--dry-run` and
`--app=<id>`) rewrites each instance. It is keyed on **the chart the instance deploys from**,
not on its application: an instance's chart is its version's chart (`version_group_id` →
`app_charts`), else its plan's selected group's. A chart is one of these layouts:

- the app's **registered** profile, for a default chart whose `source` is `profile` (Nextcloud,
  WordPress, CiviCRM);
- **generic**, for `source = generic`;
- **catalog**, for `source = helmforge`, whether it is the app's default chart or an added one.

So a Nextcloud instance on an added HelmForge chart is migrated by the *catalog* row below,
not the Nextcloud one. Like #281's migration, the rewrite reads and writes `app_instances`
with the query builder and a frozen map in the migration file (resolving the chart from the
tables, not through the models or `profileFor()`), so a later change to a model or a profile
cannot change what an old migration does. The old-versus-new values check below runs in the
artisan command and its tests, which can use the application code.

The primary volume, `app/data`:

| Chart layout | `claim` from | `access_mode` from | `storage_class` from | `size` from |
|---|---|---|---|---|
| Nextcloud | `existing_claim` | `configurations.persistence-accessMode` | `configurations.persistence-storageClass` | `chart_storage` |
| WordPress | `override.pvc.name` | `configurations.persistence-accessModes` (first of the list) | same | `chart_storage` |
| CiviCRM | `override.pvc.name` **only if** `override.pvc.override` is true, else it is a `discovered` value | `persistence-accessModes` | same | `chart_storage` |
| Catalog (HelmForge), default or added chart | `override.pvc.name` | none | same | `chart_storage` |
| Generic | `persistence-<n>-existingClaim` into `app/<name>` (name taken from `persistence-<n>-name`) | `persistence-<n>-accessMode` | `persistence-<n>-storageClass` | `persistence-<n>-size` |

The secondary volumes, which today live inside configuration blobs:

| Chart layout | From | To |
|---|---|---|
| Nextcloud (registered) | `configurations.redis` (and the plan's) `master.persistence.*`, `replica.persistence.*` | `redis-master/data`, `redis-replica/data`: `enabled`, `size`, `storage_class`; the keys are **removed from the blob** so it cannot fight the volume |
| WordPress, CiviCRM (registered) | `configurations.mariadb` `primary.persistence.*` | `mariadb/data`, the same way |
| Catalog | none: the chart's own subchart persistence was never set by kumulicp | nothing to migrate; a re-sync records the volumes the chart has |

A blob value that equals the profile default is dropped rather than copied, so an instance
that never changed it keeps following the plan and profile. An instance whose blob **enabled**
a secondary volume is listed in the dry run: its data was never part of a move.

The old per-app map lives **only inside the migration**, as a frozen array, so the runtime
code has no legacy knowledge.

The one hard part is `override.pvc.name`. For WordPress and HelmForge it is both a chosen
claim and a cluster-reported cache, and the code cannot always tell which. The rule is
**the chart values must not change**:

1. Before touching an instance, build its chart values with the old code path and keep them.
2. Migrate, build the values again with the new code path.
3. The two must be identical (this is the check the dry run reports, and the check each
   migrated instance must pass before its row is saved).

Instances where `override.pvc.name` is set and no move task recorded it as a handover are
reported as "claim looks discovered". They are migrated as `claim` (so nothing changes
today) but listed for a person to look at, because a chart that is handed the name of the
PVC it created itself is a hazard when the next upgrade renders: verify on a cluster
whether Helm then drops the PVC from the release. If it does, those instances should become
`discovered` instead.

The migration also:

- renames `persistence-accessModes` to `persistence-accessMode` in plan settings, in
  instances' persistent configurations, and in the profiles' definitions;
- deletes the migrated legacy keys (`existing_claim`, `chart_storage`, `override.pvc`)
  only in the second step below, so a rollback has something to read.

### Behaviour, in two steps

1. **Read both, write new.** `Volumes` reads `workloads.*.volumes.*` and falls back to the
   legacy keys and blob sub-keys; everything writes `workloads.*.volumes.*` only. Ship, run the dry run on a copy of production data,
   run the migration, compare values.
2. **Drop the fallback and the legacy keys.** A follow-up release removes the fallback reads
   and runs a second data migration that deletes the legacy keys.

The core is `0.x` and the README says breaking changes are expected, but the second step is
still a separate release so a bad first step can be reverted without data loss.

### Update drift

`ReleaseAspect::fingerprint()` has to include `workloads` (and drop the `override.pvc` exclusion
once `discovered` is separate). Changing a fingerprint makes every instance look out of date,
so the same release adds a re-stamp migration, as
`2026_11_17_000000_restamp_fingerprints_for_version_images` did, which recomputes and stores
the new fingerprint for instances that were up to date. Without this, changing a claim in the
new form would not show the app as needing an update.

#233 changed this aspect (`deploymentSettings()` and `chartRepoSecretId()` replace the version's
raw settings and repo login, which now come from the chart), and added
`organization_update_states`, the organization-level counterpart of the per-app state. The
fingerprint change goes in the same `instance` block as before and does not touch the chart
inputs. The re-stamp has to cover what `BaselineUpdateState` covers today; check whether any
organization-level aspect fingerprints instance settings before assuming the re-stamp only
touches per-app rows.

## Build order

Each slice is its own PR, based on the one before, with tests, and none needs a cluster.

**Slice 0 - characterization tests (no behaviour change).** Golden tests of the Helm values
for Nextcloud (with and without Redis persistence turned on), WordPress and CiviCRM (with and
without the MariaDB subchart's persistence on), Generic (one and several volumes) and a
HelmForge chart, with and without an existing claim, in each state the migration will see.
Include **a registered app with an added catalog chart** (the setup in
`tests/Feature/Applications/MultipleChartsTest.php`: the demo app plus a `gitea` chart),
because the same app then has two layouts and the tests must show each instance gets its own
chart's.
They are the safety net for every later slice. Also a test that pins how `override.pvc.name`
is written back by `JobChart`, including the "first PVC found" case with two claims.

**Slice 1 - the volume store.** `Volume`, `Volumes` (workload-aware; read both, write new),
`SettingsSnapshot`, `VolumeRules`. Unit tests, including the fallback order, a two-workload
chart, size policies (`quota` vs `fixed`) and `assign()` / `restore()` round-trips over
several volumes (port the cases from `StorageHandoverTest`).

**Slice 2 - the chart method and the declarations.** `HelmChart::volumes()` /
`volumeValues()`, the default map, claim modes, `defaultClaimName()`. Convert the five charts
one commit each, with their secondary volumes (Redis, MariaDB) declared; the slice-0 golden
tests must stay green with no edits. Convert the job charts' claim lookup to
`Volumes::claimName()`. `HelmForgeChart::setPersistence()` moves into the base class.

**Slice 2b - catalog volumes.** `ChartConventionDetector` records every volume it finds,
the stored convention gains `volumes` next to `persistence` (on the application for a default
chart, on the `app_charts` row for an added one), `HelmForgeChart` reads `volumes` through its
`catalog()` and falls back to `persistence`, and `catalog:sync --report` lists shapes it left
out. Tests in `tests/Unit/Catalog/ChartConventionDetectorTest.php` for flat, multi-volume and
subchart layouts, and in `tests/Feature/Catalog/` for a chart attached to a registered app.

**Slice 3 - replace StorageHandover.** `ApplicationWebMove`, `AwaitVolume`, `SwitchServer`,
`Applications@move_web` and the move form use `Volumes` / `VolumeRules`, with a handover per
volume and the blocker for a data-holding volume that cannot move. Delete
`StorageHandover`, `AppProfile::storageHandover()` and the five overrides; move
`StorageHandoverTest` and the `WebMoveTest` / `ApplicationWebMoveTest` setup onto the new
classes. Update `docs/server-transitions-plan.md` ("Persistent volume handover") to point
here.

**Slice 4 - data migration and fingerprint.** Migration, artisan command with `--dry-run`,
the `ReleaseAspect` change and the re-stamp migration. Test with fixtures of every legacy
shape in the table above, including the ambiguous `override.pvc.name` cases.

**Slice 5 - other standard value groups.** `resourcesValues()`, `ingressValues()`,
`imageValues()`, `replicaValues()`, one PR each, golden-test-first. Moves the
"ingress off when disabled or in maintenance" rule to one place.

**Slice 6 - schema and registry.** Extend definitions with `group` / `label` / `help` /
`visible_if`; `StandardSettings`; `InstanceSettings` registry; `InstanceSettingsSchema`
read model; the merge-and-lock save. Backend only, with tests. No UI change.

**Slice 7 - the form.** `SettingsForm.vue` and field components, the volume panel, the
read-only Advanced panel, source badges, and the raw JSON editor as the last tab with a
diff and confirmation. Feature tests for the controller (validation errors, a concurrent
system-key write surviving a save). Browser test for the happy path.

**Slice 8 - drop the fallback.** Remove the legacy reads, run the cleanup migration, delete
the frozen map's runtime users. Remove the dead `persistence-existingClaim` configurations
if the check in "Where we are" holds.

Slices 1-4 are the volume goal. Slices 6-7 are the UI goal and only depend on slice 1 (for
the volume panel), so they can start in parallel once slice 1 is in.

## Risks

| Risk | Mitigation |
|---|---|
| A migrated instance renders different Helm values and a release changes or loses data | Slice 0 golden tests; per-instance old-vs-new values check inside the migration, which aborts that row; `--dry-run`; fallback reads kept for one release |
| `override.pvc.name` is a discovered cache for some apps, so the migrated `claim` is wrong | Reported by the dry run; migrated as today's behaviour first; verify on a cluster before deciding to flip them to `discovered` |
| Fingerprint change marks every app out of date and triggers auto-updates | Re-stamp migration in the same release; check `AutoUpdates` / `UpdatePolicy` behaviour in the test for it |
| The form overwrites system keys | Merge-and-lock save; system keys are read-only in the form and the registry |
| Plugins override `storageHandover()` or read `existing_claim` / `override.pvc.name` | It is a public extension point (`AppProfile`, plugins use `CoreVersion`). Open question below |
| Plan-level `persistence-accessModes` renamed while plans are being edited | Done in the same migration; the profile accepts both names on read in step 1 |
| A secondary volume (Redis, MariaDB) is enabled on an instance and its data is not part of a move | Blocker on the move until the admin accepts starting it empty; the dry run lists instances that have one enabled |
| A StatefulSet volume cannot take a single claim name (several replicas, or a chart that only takes one for the primary) | `claim_mode` per volume; `by_name` or `none` instead of `existing_claim`; the form says why. Whether a StatefulSet adopts a pre-created claim of the template's name is confirmed on a cluster before `by_name` is offered |
| Workload and volume names become settings keys, so a chart that renames one orphans the stored values | Names are fixed in the chart class and pinned by a test; renaming one needs a migration |
| The migration or a form reads a layout from the application, so an instance on an added chart gets another chart's keys | Everything resolves through `profileFor()` / the chart row; a Slice 0 test with one app on two charts; the migration is keyed on chart identity |
| A chart's layout changes between its versions, but one convention is stored for the chart (detected from the newest version) | Known limit of the catalog today. Record the convention's chart version, re-detect on sync, and have the version test run fail when a version's values lack a declared key |
| An instance is moved to a plan on another chart and its secondary-volume settings no longer match | Values for undeclared workloads are ignored, not deleted; the primary volume's settings carry over; whether this is allowed at all is an open question |
| The catalog detector misreads a subchart's layout and writes to a key the chart ignores (Helm drops unknown keys silently) | Only recognised layouts are recorded; unknown ones are reported, not guessed; a re-sync shows the diff of the stored convention |

## Open questions

1. **Namespace**: `workloads.<workload>.volumes.<volume>` at the top level (recommended; it
   leaves room for per-workload resources, replicas and images), or under `configurations.*`
   next to the plan-level `persistence-*` settings? The second keeps one namespace but
   mixes the plan's defaults with the instance's chosen claim.
2. **Plugins**: keep `AppProfile::storageHandover()` as a deprecated shim for one minor
   version (adapting a legacy map to the new store), or remove it with the release note,
   given `0.x` minors may break?
3. **Several volumes in a move**: the plan asks for a handover per data-holding volume, and
   blocks the move when one cannot be handed over. Is blocking right, or should it warn and
   let the admin carry on with an empty secondary volume (Redis is a cache; MariaDB is not)?
   A per-volume `required` flag would let the chart decide.
4. **Secondary volumes and the quota**: they are `fixed` size and outside `storage_quota`
   and billing in this plan. Should any count towards what the customer is charged for, and
   should the plan be able to set their size?
5. **System keys**: leave them where they are and classify them in the registry
   (recommended), or move them under `system.*` as well? Moving them touches SSO, storage,
   availability and maintenance code, and the SSO move plan stores ids that read these paths.
6. **Who sees the form**: the admin page only (where the textarea is today), or also an
   organization-facing settings page? The schema's `owner` / visibility would need an
   audience field for the second.
7. **Rancher**: its values differ from Kubernetes (see the web move notes), and #233 made
   HelmForge charts deployable on Rancher (`HelmForgeProfile` is now `rancher`-compatible, with
   the repository given by name). Do the chart methods need a per-driver map, or is one map
   right for both because the driver only changes how the values are applied?
8. **Can an instance change chart?** The plan's chart is fixed once it is created, but an
   instance can be given another plan. If that can switch it between a registered chart and a
   catalog chart, do we block it, warn that secondary volumes and their data are not
   carried over, or move the primary volume's claim and size across (the model allows it,
   but the claim's PVC name was made by the old chart)?
9. **Plans on an added chart**: the plan-level `persistence-*` configurations and the
   accessModes rename apply only to plans whose chart uses a registered profile. A plan on a
   catalog chart takes its configurations from the chart's detected convention. Is it right
   for the settings form to show only what that convention detected, or should the standard
   storage group appear for every chart (and be ignored where the chart has no such key)?

## Not covered

A volume backup driver, volume expansion changes (`expand_storage` stays as it is; a larger
quota still grows only the primary volume, through the ratchet and a re-render), moving the
system keys, per-workload resources, replicas and images beyond reserving the `workloads.*`
key for them, and an organization-facing settings UI.
