# Plan: several images per app version

Status: proposal. Nothing here is implemented.

## Problem

An `AppVersion` knows about **one** container image. `name` is the image tag, and
`settings.image_repo_name` / `settings.image_registry` / `image_registry_id` say where
it comes from (`Chart::image()`, `GenericChart::values()`, `NextcloudChart::values()`).

Charts like Matrix ESS ship a dozen or more images (Synapse, Element Web, MAS,
Matrix RTC, HAProxy, Postgres, init/tool images ...). Today the only way to run such an
app is to push the extra images through **instance/profile configurations**. Nextcloud
already does this: `imaginary-image-registry|repository|tag` and the `redis` YAML (with
`image.tag`) are configurations in `NextcloudProfile`. That has real costs:

| Gap | Why it hurts |
|---|---|
| Update checks (`VersionUpdateChecker::checkImage`) only look at the primary image | A stale Synapse or Redis tag is never reported |
| Version tests (`VersionFingerprint`, `testGate`) only see the version's own settings | Changing a configuration default changes what runs without staling a passed test, so it can reach customers untested |
| Image tags sit on the instance, not the version | Upgrades and rollbacks (`ApplicationUpgrade`, `previous_version_id`) don't move the images together, and "which images are in version X?" has no answer |
| One pull secret per version (`imagePullSecrets()`) | Images from several registries can't be pulled |
| Nothing checks that the chart honours an override | Helm silently ignores unknown keys, so a renamed values path ships the chart's own image |

## Goal

A version is a **bundle**: the chart version plus every image it runs, each with its
own registry, tag (optionally digest), update policy and chart values path. Everything
that already works per version (update checks, test gate, drift detection, upgrade,
rollback) then works for the whole bundle, with no changes to the overall flow.

Non-goals: building or mirroring images, replacing Helm, per-instance image overrides
as a normal workflow (they remain possible but are flagged).

## Design

### 1. Data model

New table `app_version_images` (one row per image in a version), rather than JSON in
`settings`:

| Column | Notes |
|---|---|
| `app_version_id` | FK, cascade delete |
| `key` | Stable slug within the version: `primary`, `synapse`, `element-web`, `redis` ... Keeps identity across versions so "synapse 1.2 -> 1.3" can be diffed |
| `label` | Display name |
| `role` | `primary` (exactly one for existing apps), `component`, `job` (init/migration/tool images) |
| `source` | `override` (Kumulicp writes it into the values) or `chart` (inventory only: the chart's own default, recorded so it can be checked, scanned and diffed) |
| `registry`, `repository`, `tag`, `digest` | Same meaning as today's settings. `digest` optional, for charts that pin by digest (ESS does) |
| `registry_secret_id` | FK `repo_secrets`, per image |
| `values_path` | Where it goes in the chart values, e.g. `image` (writes `.registry/.repository/.tag/.digest`) or `synapse.image`. Default `image` |
| `tag_from_version_name` | Legacy flag. True for the backfilled primary image, so `AppVersion::name` keeps meaning "the tag" for existing apps |
| `update_policy` | `track` / `patch` / `minor` / `pinned` / `ignore` |
| `tag_pattern` | Optional regex to filter registry tags (e.g. `^\d+\.\d+\.\d+$`, to skip `-rc`, `-debian-12-rN`) |
| `group` | Optional. Images in a group are bumped together (Synapse + its admin UI) |
| `position` | Display order |

Why a table and not `settings.images` JSON: per-image FK to a registry secret,
queryable ("which versions use image X?", needed for CVE response), simple diffs
between versions, and per-image update results can be keyed without parsing. The cost
is that fingerprints and the change observer must be told about it, since they don't
get it for free from `settings` (see step 4). JSON would avoid that, but is cheaper now
and costlier later; flagged as an open question below.

`AppVersion::name` stays. For multi-image apps it becomes a release label (for example
the ESS chart version) rather than a tag, which only the `tag_from_version_name` flag
distinguishes.

Backfill migration: one `primary` image row per existing version from
`settings.image_repo_name`, `image_registry`, `image_registry_id` and `name`, with
`tag_from_version_name = true`. The old settings stay readable for one release.

### 2. Rendering

- `AppVersion::images()` relation and `AppVersion::primaryImage()`.
- `Chart::image()`, `imageRegistry()`, `imagePullSecrets()` read the primary image first
  and fall back to the old settings. `imagePullSecrets()` returns the distinct secrets
  across all images.
- `HelmChart` gets one generic step, `applyImageOverrides(array $values)`, that writes
  every `source = override` component image at its `values_path` (`Arr::set`). It runs
  **last** in `valuesWithAdditionalConfigs()`, after the plan's configurations, because a
  configuration is written as a raw Helm path (`imaginary-image-tag` becomes
  `imaginary.image.tag`) and a plan seeds every default: run earlier, a seeded default
  would put back an image other than the one that was tested. Charts keep their explicit
  primary block until they are migrated, so nothing changes for them at first.
- Precedence: version image > plan/instance configuration > profile default. The old
  `imaginary-image-*` configurations stay as the fallback for a version without an image
  for it. An instance that explicitly overrides one should show a "shadows version image"
  warning in the instance's Update list instead of silently losing; that needs UI, so it
  comes with phase 7.
- Rendering the images into the values means `ValuesContract::removedKeys()` and
  `schemaErrors()` check every image path against a candidate chart automatically.

### 3. Update checks

- `VersionUpdateChecker::check()` loops over the images. `ImageTagResolver::tags()`
  takes an image row instead of a version. Tags are fetched once per
  registry+repository per run and shared across versions (Docker Hub rate limits).
- Result shape: `update_check.images[<key>] = {current, latest, policy, error}`. Keep
  `update_check.image` as an alias for the primary image so `newerImageTag()`,
  `updateSummary()` and the existing UI keep working until they're moved over.
- `VersionComparator::newest()` gets the policy and `tag_pattern`; `pinned`/`ignore`
  images are listed but never offered.
- `digest` images: resolve the new tag's digest with a manifest HEAD and offer tag and
  digest together, never one without the other.
- A chart upgrade candidate also gets an **image diff**: for `source = chart` images,
  the new chart's default images (found by scanning its default `values.yaml` for
  `{registry,repository,tag,digest}` blocks, plus the `artifacthub.io/images` annotation
  in `Chart.yaml`) against the current set. `ChartCompatibilityChecker` reports
  added/removed/changed images, and a warning when an override pins an image older than
  the chart's own default, or points at a path the chart no longer has.

### 4. Tests, drift and fingerprints

- `VersionFingerprint::hash()` and `ReleaseAspect::fingerprint()` include the images.
  A changed tag, digest, registry or path now stales the version's tests and puts
  instances out of date for `release`, which is the point.
- **Rollout trap:** adding a key to the payload changes every existing hash, which
  would stale every passed test run and flag every instance. Keep the payload
  byte-identical when a version only has its backfilled legacy primary image (omit the
  `images` key), or run `updates:baseline` as part of the migration.
- Changes to image rows must be noticed: add `AppVersionImage` to
  `ImpactResolver::watched()`, resolved to its version's instances. Only `label` and
  `position` are cosmetic.
- `version_test_runs` gets `image_snapshot` (JSON): the exact references tested. The
  test report shows them, and it's the audit trail for "what did we actually test?".
- New first step in a test run, **image preflight** (before any instance is created, so
  failures are cheap): every reference resolves with the right secret (manifest HEAD via
  `RegistryClient`), and has a manifest for the architecture of the target cluster's
  nodes. Optionally also run `TrivyTool` over each image and show the result as a
  warning on the gate (it already exists in `app/Support/Security`).
- New check after the install/upgrade steps, **image conformance**: list the instance's
  pods through the existing Kubernetes API client and compare running images/digests to
  the version's list. This catches a chart that ignored an override, the failure Helm
  never reports.
- Existing "install on latest" and "upgrade from baseline" steps need no change:
  they run the same version, which now carries all its images.

### 5. Discovery and admin UI

- **Discover from chart** on the version page: downloads the chart at `chart_version`
  (the checker already can) and proposes rows for every image block it finds, with
  `values_path` pre-filled. Admin picks `override` or `chart` per row. A `chart` row
  is the sensible default for Matrix ESS, where the chart's release owns the image set
  and bumping the chart bumps them all; overriding is the exception.
- `VersionEdit.vue`: the single image section becomes an images table (add, remove,
  per-row registry secret, policy, pattern, group). The Versions list shows update
  badges per image.
- `Versions::store`: `copy_from = previous_version` copies all image rows;
  `copy_from = update` copies them and bumps the ones the admin ticks from the update
  list (grouped images tick together). `recommendations` in app profiles gains an
  `images` list next to the existing `image_*` keys.

### 6. Rollback

`ApplicationUpgrade` already stores `previous_version_id` and reverts to it on failure,
so with images owned by the version, rollback restores the full set atomically, which
instance-level configuration can't do.

## Phases

Each phase ships on its own and leaves existing apps unchanged.

1. **Model and backfill.** Migration, `AppVersionImage`, relations, primary-image
   accessors, backfill. Chart code unchanged. Tests: backfill, accessors.
2. **Rendering.** `applyImageOverrides`, multi-secret `imagePullSecrets` (and creating each
   secret in the cluster), copying images to new versions, and precedence. Moves Nextcloud
   `imaginary` onto image rows as the first real migration. `redis` is left for later: its
   configuration sets only a registry and tag, so its repository (the chart's default) would
   have to be guessed. The shadow warning moves to phase 7. Tests: extend
   `NextcloudChartTest`, `VersionsTest`, `RepoSecretsTest`.
3. **Update checks.** Per-image checker (`update_check.images`, with `image` kept as the
   primary image's), policies (`track`, `minor`, `patch`, `pinned`, `ignore`) and
   `tag_pattern`, digests offered only together with their new tag, a resolver cache so a
   run asks each repository once (and waits for a dead registry once), and badges for the
   other images on the apps list, versions page and dashboard. Not yet: adopting an update
   copies the other images as they are and the "new version from update" option still
   appears only for a newer primary tag; choosing which images to bump is phase 7. Tests:
   extend `VersionUpdateCheckerTest`, `VersionComparatorTest`, `RegistryClientTest`,
   `VersionsTest`.
4. **Fingerprints, drift, impact.** Images are part of `VersionFingerprint` (all of them) and
   `ReleaseAspect` (the deployed ones), omitted when the version only has the image its
   settings describe so nothing changes for it. A migration stamps the stored fingerprints
   (test runs, instances' release) that were current under the old calculation, for versions
   that already had images (Nextcloud's imaginary). Edits to an image are noted as edits to
   its version (`VersionImageObserver`), apart from what only concerns display or update
   checks. Documented in `docs/updates.md`. Tests: `VersionImageFingerprintTest`, extend
   `UpdateDriftDetectionTest`.
5. **Discovery and chart diff.** `ChartImageScanner` reads a packaged chart's default values for
   image blocks (`registry`/`repository`/`tag`/`digest` at any depth, which Kumulicp can write
   to), whole references under a key named image (recorded only) and the `artifacthub.io/images`
   annotation (recorded only, no path). An **Images** tab on the version finds them in the
   version's chart (`ChartImageDiscovery`) and adds the ones picked, as `override` or the
   chart's own (`chart`, which is not checked for updates by default). `ChartCompatibilityChecker`
   reports how the images change in a newer chart (`image_changes`, shown with the chart that
   is on offer) and warns about an override whose path the chart no longer has, or that is older
   than the chart's own. Subcharts' values are not read. Removing an image is on the tab;
   editing one (and the rest of the images UI) is phase 7. Tests: `ChartImageScannerTest`,
   `VersionImagesPageTest`, extend `VersionUpdateCheckerTest`, `VersionImagesTest`.
6. **Test-run safety.** `image_snapshot`, preflight, conformance check, optional Trivy.
7. **Adopt-update flow and UI polish.** An image can be edited on the Images tab (what it runs,
   its registry login, and how it is checked: policy, tag pattern, group); the one the version's
   name and settings describe only has the checks, as where it comes from is the version's.
   Adding a version from an update lists the other images that have a newer tag, all picked, to
   untick; images in the same group move together (`AppVersion::adoptImageUpdates()`), and an
   update can be adopted when only those images have a newer tag. An instance that sets, by
   itself, a part of an image its version sets (`imaginary-image-tag`) is warned that the
   version's image is used instead (`ShadowedImages`, on the app's page). The version form points
   to the Images tab. Not done: a browser test of the tab (the Inertia props and the actions are
   covered by feature tests), and the version form's own image fields are still the primary
   image's. Tests: extend `VersionImagesPageTest`, `VersionsTest`, `VersionImagesTest`,
   `UpdatesTest`.
8. **Pilot and cleanup.** New Matrix ESS profile and chart as the acceptance case, with
   Nextcloud as the migration case. Remove the deprecated configuration keys. Write
   `docs/updates.md` section "What a version contains".

Phases 1-4 deliver the stated goal (update checks and test gating for every image).
5-8 make it pleasant for chart-owned image sets.

## Risks

- **Drift/stale flood** on rollout (fingerprints change). Mitigated by the identical
  payload rule and a baseline in the migration; assert it in a test.
- **Registry limits and size.** N images x daily checks; mitigated by the shared tag
  cache, pagination limits in `RegistryClient::tags`, and `ignore` policy.
- **Charts with images only in templates** (not in default values) can't be discovered
  by scanning values; they need manual rows. Acceptable, and the conformance check
  still sees them at runtime.
- **Tag semantics.** Non-semver tags (dated `20261109_101109`, `8.0.1-debian-12-r1`)
  are why `tag_pattern` and `pinned` exist; the comparator needs fixtures for each shape.
- **Instance overrides** can still defeat testing; they're surfaced, not forbidden.

## Open questions

1. Table (recommended) or `settings.images` JSON?
2. Is `chart`-sourced inventory (the chart owns the images) in the first cut, or only
   explicit overrides? Matrix ESS leans on the former.
3. Should `name` keep being the tag for single-image apps forever, or get a separate
   `tag` and become a pure label everywhere?
4. Should Trivy findings block the test gate or only warn?
5. Non-Helm drivers (the Docker mail server chart): in scope, or Helm only?
