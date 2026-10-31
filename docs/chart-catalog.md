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

## Not covered

SSO/LDAP, roles, external database servers (charts use their bundled
subcharts), post-install user sync, and the Rancher driver (it can only
install from repositories registered in Rancher).
