# What a version contains

An app version (`AppVersion`) is a **bundle**: the chart version it deploys and every
container image it runs, with its roles and settings. Because the images belong to the
version, everything done per version works for all of them: update checks, the test
gate, finding out which instances are out of date, upgrading and rolling back (a rollback
restores the whole set).

The design and the phases it was built in are in `docs/multi-image-versions-plan.md`.
How a version's definition is kept up to date on instances is in `docs/updates.md`.

## The images

One row of `app_version_images` (`AppVersionImage`) per image:

| | |
|---|---|
| `key` | Names it within the version and across versions (`primary`, `imaginary`, `synapse` ...). Unique per version. |
| `role` | `primary` (the app's own image), `component` (another image the chart runs) or `job` (run by a job). |
| `source` | `override`: Kumulicp writes it into the chart's values, so it is what runs. `chart`: the chart's own image, recorded as inventory so it can be listed, diffed and seen; nothing is written. |
| `registry`, `repository`, `tag`, `digest` | Where it comes from. A digest is for charts that pin by digest. |
| `registry_secret_id` | The login to pull it with, per image. |
| `values_path` | Where it goes in the chart's values: `imaginary.image` writes `imaginary.image.registry`, `.repository`, `.tag` and `.digest`. Empty for an image the chart only lists. |
| `update_policy`, `tag_pattern`, `group` | How it is checked for updates (below). |
| `tag_from_version_name` | The version's name is its tag. |

**The primary image** is the one a single-image app has always had. Its repository,
registry, login and tag are the version's settings (`image_repo_name`, `image_registry`,
`image_registry_id`) and its **name** (the tag), and the charts still read them from there;
the primary row follows them (`AppVersion::syncLegacyPrimaryImage()`, on save and before an
update check). That is why it can only be given update-check settings on the Images tab. A
bulk update of `app_versions` skips the model events, so code that does one has to update
the images too (as the repo secrets' mass migration does).

### Getting images into a version

- A new version **copies** the other images of the version it was made from
  (`copyImagesFrom()`), or gets the ones its profile recommends
  (`recommendations['images']`, `addRecommendedImages()`), also when an app is first set up.
- **Find images in the chart** on the Images tab reads the chart's default values
  (`ChartImageScanner`) for blocks of registry/repository/tag/digest at any depth (which
  Kumulicp can write to), for whole references under a key named image, and for the chart's
  `artifacthub.io/images` annotation (the last two are recorded only). Pick the ones to add,
  as set by Kumulicp or the chart's own. Subcharts' values aren't read.
- An image can be edited or removed on the same tab.

## What runs

`HelmChart::valuesWithAdditionalConfigs()` builds a chart's values, and then
`applyImageOverrides()` writes every `component` image with source `override` at its
`values_path`, only the parts it has (a null would make Helm drop the chart's default) and
leaving what else the chart keeps there (`pullPolicy` ...). It runs **last**, after the
plan's configurations: a configuration is a raw Helm path (`imaginary-image-tag` is
`imaginary.image.tag`) and a plan seeds every default, so earlier a seeded default would put
back an image other than the one that was tested.

- The pod pulls with every login its images need, once each (`imagePullSecrets()`), and the
  Secret for each is created in the cluster before the deploy (`ensureImageRegistryAuth()`).
- A chart can still set an image itself. A `component` it sets that way is written over by
  the version's image, if there is one. Nextcloud's imaginary image is one: the chart gives
  the profile's recommended image (`NextcloudProfile::$recommendations['images']`) to a
  version without its own, which is what runs for it; there is no configuration for it.
- An instance that sets, by itself, a part of an image its version sets (an `override` or
  `configurations` entry named like the path: `imaginary-image-tag`) is ignored, and the
  app's admin page says so (`ShadowedImages`). A plan's own settings don't count: a plan
  seeds every default.

## Updates

The daily check looks at every image (`VersionUpdateChecker`), by registry and repository
once however many versions use it. Per image, under `update_check.images`; `update_check.image`
is the primary image's, as before.

- `update_policy`: `track` (any newer tag of the same shape), `minor` (same major version),
  `patch` (same major and minor), `pinned` (keep this tag) and `ignore` (don't check, and
  what the chart's own images start with). The last two don't ask the registry.
- `tag_pattern`: a regular expression the registry's tags must match first (dated tags, tags
  with a suffix).
- An image kept by digest is only offered with the digest of its new tag.
- A newer chart is compared with the one in use for the images it runs: added, removed,
  changed (`ChartImageDiff`), shown with the chart on offer; an image the version sets that
  the chart no longer has a place for, or that is older than the chart's own, is a warning.

**Adopting an update** (Add version, from an update) lists the other images with a newer tag,
all picked; only the picked ones move to it in the new version, images with the same `group`
together (`AppVersion::adoptImageUpdates()`). An update can be adopted when only those images
are newer; the new version is then named by hand.

## Tests and out of date instances

An image's `source`, registry, repository, tag, digest, login, role and `values_path` are in
the fingerprint of the version's tests (all of them) and of the release of its instances
(the deployed ones). Its label, place in the list, policy, pattern and group are not. See
"A version's images" in `docs/updates.md`.

## Not done

- **A record of what a test run covered, a check that every image can be pulled before a test
  starts, and a check that what runs is what the version says** (phase 6 of the plan). A
  chart that ignores an override (Helm ignores a values key it doesn't know) is still only
  noticed by the admin trying the app, or by `ValuesContract` when the version has an instance.
- Scanning the images in a **vulnerability scanner** per image.
- **Redis** (Nextcloud's `redis` configuration) is still a configuration: it records a
  registry and tag but not the repository, which is the chart's default.
- The version form's image fields are still the primary image's; the rest are on the Images tab.
- Images in a chart's **subcharts**, or only in its templates, aren't found; add them by hand.
- Only Helm charts.

## Where it is

| | |
|---|---|
| `AppVersionImage`, `AppVersion` (`images()`, `primaryImage()`, `extraImages()`, `imageInputs()`, `newerImageTags()`, `adoptImageUpdates()`) | the model |
| `HelmChart::applyImageOverrides()`, `Chart::imagePullSecrets()` | what runs |
| `Support/VersionUpdates/` (`VersionUpdateChecker`, `ImageTagResolver`, `VersionComparator`, `ChartImageScanner`, `ChartImageDiff`, `ChartImageDiscovery`, `ChartRepositoryResolver`, `ChartCompatibilityChecker`) | updates and discovery |
| `Support/VersionTesting/VersionFingerprint`, `Updates/Aspects/ReleaseAspect`, `Updates/Detection/VersionImageObserver` | tests and drift |
| `Updates/ShadowedImages` | settings that are ignored |
| `Http/Controllers/Admin/Applications/Versions/Images`, `Pages/Admin/Applications/Versions/VersionImages.vue` | the Images tab |
