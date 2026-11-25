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

### 1. One volume location, the same for every chart

Instance settings get one namespace for volumes:

```
settings.volumes.<key> = {
    claim:         "name of an existing PVC" | null,   // explicit, chosen by a person or a move
    discovered:    "name the cluster reported" | null, // cache written by the release; never fed to the chart
    size:          Gi (int),                           // the ratchet that is chart_storage today
    storage_class: string | null,
    access_mode:   string | null,                      // always a single string; the chart makes a list if it needs one
}
```

- `<key>` is `data` for every single-volume chart and the volume's `name` for the generic
  chart, so multiple volumes are supported by the model from the start.
- **`claim` and `discovered` are separate on purpose.** Today `override.pvc.name` is both:
  the handover writes it, and `JobChart` caches the cluster's answer in it, and WordPress and
  HelmForge feed it back to the chart as `existingClaim`. That mix is why
  `ReleaseAspect` has to exclude `override.pvc`. After the split, only `claim` reaches the
  chart; `discovered` is read only by jobs, and the `override.pvc` exclusion goes away.
- Defaults still come from the plan and the profile through the existing `persistence-*`
  configurations, with the name normalised to **`persistence-accessMode`** (one string) in
  every profile. Resolution for each field: `volumes.<key>.<field>` > plan configuration >
  profile default, the same order `configuration()` uses.
- The plan decides **which volumes exist** (the generic chart's `persistence-<n>-name` /
  `mountPath` items, or the single `data` volume); the instance only holds what is specific to
  it (a claim, a grown size, an overridden class).

A small class owns all reads and writes, so no other code spells these paths:

```php
// App\Support\Volumes\Volume (value object) and App\Support\Volumes\Volumes (the store)
Volumes::for($appInstance)->get('data');         // resolved Volume: enabled, size, storageClass, accessMode, claim
Volumes::for($appInstance)->claimName('data');   // claim ?: discovered ?: the chart's default claim name
Volumes::for($appInstance)->assign('data', $values);   // writes volumes.data.*, returns the previous values
Volumes::for($appInstance)->restore($before);
```

`assign()` / `restore()` are the part of `StorageHandover` that is not chart-specific. They
write `volumes.<key>.*` and return what was there (`['path' => ['value' => ...] | null]`),
which is what `SwitchServer` already stores on the task. That snapshot helper is not about
volumes, so it becomes a small generic `SettingsSnapshot` (apply a set of dot-path writes,
return what was there, put it back), which the SSO move can use too.

### 2. One chart-side method per value group

`HelmChart` gets one method per group. Each takes a **map** of where that chart keeps the
keys, so the chart class declares its layout once and never writes the values itself:

```php
// in a chart's values()
'persistence' => $this->persistenceValues('data', [
    'path'          => 'persistence',        // base key; 'enabled', 'size', ... below are relative
    'enabled'       => 'enabled',
    'size'          => 'size',               // written as "<n>Gi"
    'storage_class' => 'storageClass',
    'access_mode'   => 'accessMode',         // or ['accessModes', 'as' => 'list']
    'claim'         => 'existingClaim',
    'claim_flag'    => null,                 // CiviCRM: 'override' => true when a claim is set
]),
```

- A **default map** covers the common bitnami-style layout
  (`persistence.{enabled,size,storageClass,accessModes,existingClaim}`), so most charts pass
  nothing.
- `ChartConventionDetector` already records, for each catalog chart, where it keeps `size`,
  `storage_class` and `existing_claim`. **That detected shape becomes the map format**, and
  `HelmForgeChart::setPersistence()` moves up into `HelmChart`. Hand-written charts declare the
  same shape, so there is one vocabulary.
- The generic chart's several volumes call `persistenceValues($name, $map)` per volume and
  keep building the `persistentVolumeClaims` / `volumes` / `mounts` lists, which are
  genuinely chart-specific.
- "Can this chart be given a claim that already exists?" is answered by the map: it has a
  `claim` path. That replaces `StorageHandover::supports()` and `storageHandover() === null`.
- `defaultClaimName()` (what the chart would name its own PVC, e.g.
  `<fullname>-nextcloud`, `<fullname>-<volume>`) is declared once per chart and used by
  `Volumes::claimName()`. The job charts then drop their separate lookups
  (`JobChart::persistentVolumeClaim()` and the `NextcloudJobChart` override; `WordpressJobChart`
  only calls the inherited one).

The same treatment, in later slices, for the blocks that are repeated almost verbatim:

| Method | Repeated today in |
|---|---|
| `resourcesValues($path)` | Nextcloud, WordPress, CiviCRM, Generic, HelmForge (`resources-requests/limits-cpu/memory`) |
| `ingressValues($style)` | WordPress, CiviCRM, Generic, HelmForge (the annotations/tls/enabled expression, including the "disabled app or maintenance mode turns ingress off" rule, is copied into each) |
| `imageValues($path)` | Nextcloud, WordPress, CiviCRM, Generic |
| `replicaValues($path)` | all (`replicaCount()` already exists; only the key differs) |

Because the "ingress off while disabled or in maintenance" rule is a safety rule, it should
live in exactly one place.

### 3. A settings schema the UI can be built from

Extend what profiles already declare instead of adding a second system.

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
volume group is a dedicated panel: claim (with a warning that this changes where data lives),
size (read-only when it follows the quota), class, access mode.

### 4. Moves use the volume methods

`StorageHandover` goes away:

| Today | After |
|---|---|
| `StorageHandover::rules()` / `rulesFor()` | `VolumeRules::rules($prefix)`, a plain rules array (claim, access mode, storage class, size; the same regexes) |
| `StorageHandover::map()`, `supports()`, `fields()` | the chart's map says whether it takes a claim and which fields it has |
| `StorageHandover::apply()` / `restore()` | `Volumes::assign()` / `restore()` over the generic snapshot helper |
| `AppProfile::storageHandover()` and five overrides | removed |
| `DemoAppProfile::$storage_handover` test hook | a chart map on `DemoHelmChart` |
| move form fields | the same four fields, from the volume definition |

`ApplicationWebMove`, `AwaitVolume` and `SwitchServer` keep their shape; they call
`Volumes` instead. Today a generic app with more than one volume is refused a handover
("which of several the claim is for is not something to guess"); with `volumes.<name>` the
move form can ask for a claim per volume. That is an extension, not a requirement of this
plan (see open questions).

## Migration

### Data

A data migration (and an artisan command that runs the same code, with `--dry-run` and
`--app=<id>`) rewrites each instance, keyed on its application:

| App | `claim` from | `access_mode` from | `storage_class` from | `size` from |
|---|---|---|---|---|
| Nextcloud | `existing_claim` | `configurations.persistence-accessMode` | `configurations.persistence-storageClass` | `chart_storage` |
| WordPress | `override.pvc.name` | `configurations.persistence-accessModes` (first of the list) | same | `chart_storage` |
| CiviCRM | `override.pvc.name` **only if** `override.pvc.override` is true, else it is a `discovered` value | `persistence-accessModes` | same | `chart_storage` |
| HelmForge | `override.pvc.name` | none | same | `chart_storage` |
| Generic | `persistence-<n>-existingClaim` into `volumes.<name>` (name taken from `persistence-<n>-name`) | `persistence-<n>-accessMode` | `persistence-<n>-storageClass` | `persistence-<n>-size` |

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

1. **Read both, write new.** `Volumes` reads `volumes.*` and falls back to the legacy keys;
   everything writes `volumes.*` only. Ship, run the dry run on a copy of production data,
   run the migration, compare values.
2. **Drop the fallback and the legacy keys.** A follow-up release removes the fallback reads
   and runs a second data migration that deletes the legacy keys.

The core is `0.x` and the README says breaking changes are expected, but the second step is
still a separate release so a bad first step can be reverted without data loss.

### Update drift

`ReleaseAspect::fingerprint()` has to include `volumes` (and drop the `override.pvc` exclusion
once `discovered` is separate). Changing a fingerprint makes every instance look out of date,
so the same release adds a re-stamp migration, as
`2026_11_17_000000_restamp_fingerprints_for_version_images` did, which recomputes and stores
the new fingerprint for instances that were up to date. Without this, changing a claim in the
new form would not show the app as needing an update.

## Build order

Each slice is its own PR, based on the one before, with tests, and none needs a cluster.

**Slice 0 - characterization tests (no behaviour change).** Golden tests of the Helm values
for Nextcloud, WordPress, CiviCRM, Generic (one and several volumes) and a HelmForge chart,
with and without an existing claim, in each state the migration will see. They are the
safety net for every later slice. Also a test that pins how `override.pvc.name` is written
back by `JobChart`.

**Slice 1 - the volume store.** `Volume`, `Volumes` (read both, write new), `SettingsSnapshot`,
`VolumeRules`. Unit tests, including the fallback order and `assign()` / `restore()`
round-trips (port the cases from `StorageHandoverTest`).

**Slice 2 - the chart method and the maps.** `HelmChart::persistenceValues()`, the default
map, `defaultClaimName()`. Convert the five charts one commit each; the slice-0 golden tests
must stay green with no edits. Convert the job charts' claim lookup to `Volumes::claimName()`.
`HelmForgeChart::setPersistence()` moves into the base class.

**Slice 3 - replace StorageHandover.** `ApplicationWebMove`, `AwaitVolume`, `SwitchServer`,
`Applications@move_web` and the move form use `Volumes` / `VolumeRules`. Delete
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

## Open questions

1. **Namespace**: `volumes.*` at the top level (recommended), or under `configurations.*`
   next to the plan-level `persistence-*` settings? The second keeps one namespace but
   mixes the plan's defaults with the instance's chosen claim.
2. **Plugins**: keep `AppProfile::storageHandover()` as a deprecated shim for one minor
   version (adapting a legacy map to the new store), or remove it with the release note,
   given `0.x` minors may break?
3. **Several volumes in a move**: ask for a claim per volume now (the model allows it), or
   keep today's "one volume only" limit in the first pass?
4. **System keys**: leave them where they are and classify them in the registry
   (recommended), or move them under `system.*` as well? Moving them touches SSO, storage,
   availability and maintenance code, and the SSO move plan stores ids that read these paths.
5. **Who sees the form**: the admin page only (where the textarea is today), or also an
   organization-facing settings page? The schema's `owner` / visibility would need an
   audience field for the second.
6. **Rancher**: its values differ from Kubernetes (see the web move notes). Do the chart
   methods need a per-driver map, or is one map right for both because the driver only
   changes how the values are applied?

## Not covered

A volume backup driver, volume expansion changes (`expand_storage` stays as it is), moving
the system keys, and an organization-facing settings UI.
