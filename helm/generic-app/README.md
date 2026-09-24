# generic-app

Minimal, image-only Helm chart backing kumulicp's built-in `generic` app
profile (`App\Integrations\Applications\GenericAppProfile`). It only knows
about a container image, a single port, env vars, an optional
PVC-backed volume, and an optional Ingress -- see `values.yaml` for the
full schema and `App\Integrations\ServerManagers\Rancher\Charts\GenericChart`
for how kumulicp populates it per app instance.

## Publishing

The chart's source lives here for now, but the install job (`helm upgrade
--install ... --repo <url>` / `oci://...`, see
`App\Integrations\ServerManagers\HelmKubernetes\API\HelmInstaller`) only
ever fetches a chart from a Helm repo or an OCI registry -- it never reads
this git checkout. Before a version of the `generic` app can actually be
activated, this chart needs to be packaged and published somewhere
reachable from target clusters (e.g. `helm package` + `helm repo index`
pushed to a static host/OCI registry), and that location set as the
version's "Helm Repo" (`helm_repo_name`) with `chart_name: generic-app`
and the matching `chart_version`.

## Local validation

```sh
helm lint helm/generic-app
helm template test helm/generic-app --set image.repository=nginx --set image.tag=latest
```
