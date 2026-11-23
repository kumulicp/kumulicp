# Chart catalog

Imports every chart of the HelmForge Helm repository as a disabled application,
deployed by one `HelmForgeProfile` and `HelmForgeChart` instead of a
hand-written `FooProfile` + `FooChart` per app.

```sh
php artisan catalog:sync            # all enabled chart sources (also runs daily)
php artisan catalog:sync helmforge --report   # one source, listing skipped/failed/limited charts
```

## How it works

- **Sources** are rows in `chart_sources` (a seeded `helmforge` row points at
  `https://repo.helmforge.dev`). `ChartCatalogSync` reads the repo's
  `index.yaml`, then for each chart creates an `Application` (disabled) and
  its newest `catalog.versions_per_chart` `AppVersion`s (deactivated) whose
  settings are the usual `chart_name` / `chart_version` / `helm_repo_name`, so
  the existing install, upgrade and version-update code works unchanged.
- **Convention detection**: `ChartConventionDetector` reads the newest chart's
  default values and records which keys take replicas, labels, ingress,
  persistence, resources, image pull secrets and the admin password. The
  result and the plan options derived from it are stored in
  `applications.catalog`.
- **Deploying**: `ApplicationService::profile()` returns a `HelmForgeProfile`
  for an app with no registered profile whose `source` is `helmforge`; its
  chart class, `HelmForgeChart`, writes Kumulicp's settings only to the keys
  the chart has.
- **Overrides** for charts that don't follow the common layout go in
  `config/catalog.php` (`overrides.<chart>`); a hand-written profile for the
  same slug always wins over the catalog one.

## Application sources

`applications.source` decides how an app is deployed:

| source      | deployed by                                    | set when                          |
|-------------|------------------------------------------------|-----------------------------------|
| `profile`   | its registered profile (built-in or a plugin)  | the profile's app row is created  |
| `generic`   | `GenericAppProfile` / `GenericChart`           | an admin adds an app in the UI    |
| `helmforge` | `HelmForgeProfile` / `HelmForgeChart`          | `catalog:sync` imports the chart  |

A registered profile for the slug always wins, whatever the source says.
Other chart repositories follow their own values schema, so `catalog:sync`
refuses any chart source other than `helmforge` (`ApplicationSource::forChartSource()`)
until it gets its own profile, chart class and source value.

## Several charts for one app

An app can be deployed from more than one chart: the registered Nextcloud
chart and HelmForge's, say. Each is an `app_charts` row with its own version
groups; the app's first chart is its default, and a plan picks another by
picking one of its version groups (plan edit, "Version group").

- **Adding one**: the app's *Charts* tab, or `ChartCatalogSync::attach()`. The
  chart is added off, with the newest `catalog.versions_per_chart` versions in a
  group of its own, and its `source` and `catalog` kept on the chart row (the
  app is left as it was). `catalog:sync` keeps added charts up to date.
- **Deploying**: `ApplicationService::profileFor()` gives an install, plan or
  chart the profile of its chart. The default chart uses the app's own profile; a
  catalog chart added to another kind of app uses a `HelmForgeProfile` built from
  the chart's catalog data, so the registered profile's features, configurations,
  sidecars and env vars do not apply to it.
- **Plans**: only the groups of enabled charts are offered, and a plan with
  subscribers cannot move to another chart. A chart that plans use cannot be turned off.
- **Version names** are the app's own, so a version of an added chart whose name
  the app already has is skipped.

Not yet chart-aware: activation type (`job` or `chart`) and the `Nextcloud`
instance extensions, which still follow the app.

## Not covered

SSO/LDAP, roles, external database servers (charts use their bundled
subcharts), post-install user sync, and the Rancher driver (it can only
install from repositories registered in Rancher).
