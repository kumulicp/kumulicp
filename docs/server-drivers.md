# Web server drivers

A web server (`interface` on the Server) is how KumuliCP deploys apps into a
cluster. There are two drivers, and one is built on the other:

- **`kubernetes`** talks to any standard Kubernetes API directly. It is the
  base: everything a driver needs is implemented here.
- **`rancher`** is the Kubernetes driver pointed at a cluster that Rancher
  manages. Its classes extend the Kubernetes ones and override only what
  differs.

Both are reached the same way, through the server's web interface
(`$org_server->connect()` or `$app_instance->connect('web')`), which implements
`AppInterface` (apps), `JobInterface` (one-off Jobs) and `OrganizationInterface`
(the organization's namespace). Code outside the drivers should never build a
driver's API classes itself.

## Layout

```
app/Integrations/ServerManagers/
  Kubernetes/
    Kubernetes.php        base class: org/server context, api(), clusterApi()
    API/                  one class per resource (Namespace, Ingress, Job, ...)
    Interfaces/           KubernetesWebInterface
    Services/             DomainMiddlewareService, OrganizationServices
    Support/              KubernetesApiClient, K8sCredentialContext, Helm*
    Charts/               the chart/value builders both drivers use
    Actions/              RunJob (runs a Job through either driver)
  Rancher/
    API/                  the same resource classes, extending Kubernetes\API
    Interfaces/           RancherWebInterface extends KubernetesWebInterface
    Services/             DomainMiddlewareService (adds two Rancher-only methods)
    Support/              RancherApiClient, credential contexts, UsesRancher
```

## How Rancher differs

Rancher reaches a cluster two ways, and the driver uses both:

| Route | Used for | Client | Credentials |
|---|---|---|---|
| Rancher's own API (Steve), `/v1/{group.plural}/...` | the resources Rancher manages and reports state for: namespaces, claims, ingresses, middlewares, Jobs, catalog apps | `RancherApiClient` (`api()`) | `RancherCredentialContext`: API key and secret as basic auth |
| Rancher's cluster proxy, `/k8s/clusters/<id>/...` | plain Kubernetes objects: chart Secrets, looking a Job up | `KubernetesApiClient` (`clusterApi()`) | `RancherClusterCredentials`: bearer `key:secret`, cluster from the `rancher_cluster_id` setting (default `local`) |

`api()` is how a driver manages its resources; `clusterApi()` is the cluster's
own Kubernetes API. For the Kubernetes driver they are the same client.

Everything else Rancher does differently is an override of one of these
extension points, in the Rancher class for that resource:

| What differs | Extension point |
|---|---|
| Base URL and authentication | `K8sCredentialContext::baseUrl()`, `authenticate()`; `Kubernetes::credentials()` |
| Which client a class uses | `Kubernetes::makeApiClient()`, `clusterApi()` (the `UsesRancher` trait sets both) |
| URL layout and write verbs | `KubernetesApiClient::path()`, `createPath()`, `createObject()`, `updateObject()`, `deleteOptions()`, `RESOURCES` |
| Errors | `KubernetesApiClient::result()`, `connectionFailure()` (Rancher throws on server errors and unreachable servers) |
| Reading status off an object | each resource's `state()` (Rancher reads `metadata.state`) |
| Building a manifest | each resource's `manifest()`, `storageClass()` (Rancher adds its project labels and a Longhorn default) |
| Installing apps | `Application`: Kubernetes runs `helm` in an in-cluster Job; Rancher posts to its catalog (`install`, `upgrade`, `uninstall`) |
| Recovering a stuck release | `canRecoverStuckRelease()` (Rancher manages its own releases, so `false`) |

To add another driver, extend the Kubernetes classes the same way: supply a
client and credentials, then override only the resources whose paths, status or
manifests differ.

## Behaviour both drivers share

- **Chart Secrets.** Charts put sensitive values in a `ChartSecrets` store,
  delivered as a Kubernetes Secret (through `clusterApi()`) before the workload
  that references it. `Chart::secretsDelivered()` decides, per web server,
  whether a chart may reference one.
- **Jobs.** `runJob()` / `jobStatus()` / `jobLogs()` take the namespace the Job
  runs in. `runJob()` returns `['status' => 'success'|'failed', 'response' => ...]`
  rather than throwing when the cluster refuses the Job, so callers must check
  `status`, not truthiness.
- **Shared-plan children.** An app instance for which
  `AppInstance::sharedPlanParent()` is set has no release of its own: add,
  update and delete rebuild its parent's release instead.

## Things that must not be renamed

`RunJob`'s slug (`run_rancher_job`) and group (`rancher`) are stored on task
rows and queried by name; the class moved, but those strings did not. The log
and error messages under `messages.api.rancher.*` are used by both drivers.

## Testing

`tests/Unit/Kubernetes` covers the Kubernetes driver and its extension points
(`ExtensionPointsTest`); `tests/Unit/Rancher` covers what Rancher sends and how
it reads state (`RancherDriverTest`) and its Secret handling
(`RancherSecretsTest`). To exercise the Kubernetes driver against a real
cluster, see [testing-kubernetes-driver.md](testing-kubernetes-driver.md).
