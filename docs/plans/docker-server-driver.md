# Plan: Docker web server driver

Status: proposal. Nothing here is implemented.

## Goal

Let an admin register a plain Docker host as a **web server** (`type = web`,
`interface = docker`) and deploy KumuliCP apps onto it, the same way they can
today onto a Kubernetes cluster (`kubernetes`) or a Rancher-managed one
(`rancher`).

"Like Portainer" is an analogy for the *kind* of thing being added (a manager
for Docker hosts, as Rancher is for Kubernetes). There is no Portainer
integration. The driver talks to the Docker Engine API directly.

Decisions already made:

- **Reverse proxy is not mandated to be Traefik**, but the driver must manage
  one, because without routing and TLS a deployed container is unreachable
  by domain. Proxy handling is therefore a first-class, pluggable part of the
  driver (see "Reverse proxy strategy").
- **Storage limits are ignored.** Docker volumes are created without a size
  and no quota is enforced or reported. This is a deliberate non-goal.

## What the code looks like today (the constraints)

| Fact | Where | Consequence |
|---|---|---|
| Drivers are registered by name in a map | `app/Services/ServerInterfaceService.php` (`$interfaces['web']`) | One line to register `docker`. The admin UI's interface dropdown comes from `ServerInterface::all('web')` (`Admin/Servers.php`). |
| A driver is a *Profile* (form fields, `interface()`, optional `configTest()`, optional `defaultSettings()`) and a *WebInterface* | `Kubernetes/KubernetesProfile.php`, `Rancher/RancherProfile.php` | Same shape for `DockerProfile` / `DockerWebInterface`. |
| The web interface implements `AppInterface`, `JobInterface`, `OrganizationInterface` | `app/Contracts/ServerManager/*`, `app/Contracts/OrganizationInterface.php` | These contracts are already driver-neutral. Docker implements the same three. |
| Callers always go through `$app_instance->connect('web')` / `$org_server->connect()` | `ServerInterfaceService::connect()` (calls `existsOrganization()` / `addOrganization()` on first connect) | No caller outside the driver changes, as long as the contracts are honoured. |
| **App deployment is Helm-chart shaped.** `AppInstanceService::charts()` only returns `HelmChart` instances; `KubernetesWebInterface::add/update/delete` loop over them | `app/Services/AppInstanceService.php:69`, `Kubernetes/Interfaces/KubernetesWebInterface.php` | This is the real seam. Docker needs a non-Helm description of "what to run". |
| Only the `generic` app is image-based. Nextcloud, WordPress and CiviCRM are Helm charts with chart-specific values and Job charts | `GenericAppProfile`, `NextcloudChart`, `WordpressChart`, `CiviCRMStandaloneChart` | Docker v1 supports the generic app only. |
| `GenericChart::values()` already holds everything a single-container deployment needs: image, env, resources, volumes, one ingress host, probe path, run-as user, replicas | `Kubernetes/Charts/GenericChart.php` | Source of truth to share, not duplicate. |
| Compatibility flags (`rancher`, `helm_chart`, ...) gate what an app can do | `AppProfile::compatibilityCatalog()`, `Admin/Applications/Versions.php:185` | Add a flag so admins can see which apps can run on Docker. |
| `Chart::webServerDeliversSecrets()` hard-codes `['kubernetes','rancher']` | `Kubernetes/Charts/Chart.php` | Docker is not in the list at first, so secrets fall back to plain env values. |
| `VersionUpdateChecker` / `ChartCompatibilityChecker` branch on `interface === 'rancher' \| 'kubernetes'` | `app/Support/VersionUpdates/*` | Must treat `docker` as "not applicable", never as an error. |
| Redirect domains use Traefik Middleware + Ingress objects on Kubernetes (gated by the `traefik_middleware` server setting) | `Kubernetes/Services/DomainMiddlewareService.php` | Docker needs the proxy-neutral equivalent. |
| `RunJob` action slug `run_rancher_job` / group `rancher` is stored on task rows | `ActionService.php:107`, `docs/server-drivers.md` | Do not rename. Docker reuses it through `JobInterface`. |
| Test fakes and the `server_manager_drivers` dataset only know `fake` and `rancher` | `tests/Pest.php`, `tests/Support/Concerns/TestsWithServerInterfaces.php` | Extend with `docker`. |
| `DockerMailServer/` is an *email* driver | `ServerManagers/DockerMailServer` | No clash. The new namespace is `Docker/`. |

## Design decisions

1. **Talk to the Docker Engine API directly over HTTP(S)**, mirroring how the
   Kubernetes driver uses the REST API with no CLI on the app host. Laravel's
   `Http` client, no `docker` binary, no new composer dependency.
   - Transport: TCP+TLS with a client certificate (`https://host:2376`) as the
     primary mode. Unix socket (`unix:///var/run/docker.sock`, via curl's
     `CURLOPT_UNIX_SOCKET_PATH`) as a second mode for same-host installs.
   - Credentials reuse `servers` columns, like `K8sCredentialContext`:
     `address` = endpoint, `ca_cert` = CA PEM, `api_key` = client cert,
     `api_secret` = client key (both already encrypted and hidden). Temp files
     are 0600, per call, always deleted. No migration; per-server knobs go in
     `settings` with a `docker_` prefix.
2. **One Server = one Docker host.** Placement across hosts is already
   expressed by plans pointing at a web server; no scheduler is built.
3. **"Namespace" = a per-organization Docker network** (`kumulicp-<org-slug>`)
   plus a name prefix. `existsOrganization()` / `addOrganization()` /
   `deleteOrganization()` create and remove that network. Orgs share no
   network, except the proxy's.
4. **Plain Engine containers, not Compose or Swarm.** KumuliCP is already the
   reconciler and already stamps `kumulicp.io/{app,instance,type}` labels
   (`Chart::labels()`), so containers are found by label. Update strategy is
   *recreate*: pull, create new, stop and remove old, start. A config-hash
   label makes `update()` idempotent and detects drift.
5. **Runtime-neutral workload description.** Extract a `WorkloadSpec` value
   object from `GenericChart` (image, tag, pull policy, registry auth, env,
   secret env, CPU/memory limits, volume names and mount paths, port, probe
   path, run-as user and group, replicas, hosts, TLS flag, labels).
   `GenericChart::values()` is rewritten to consume it with **byte-identical
   output** (guarded by `GenericAppChartTest`), and `DockerWorkload` consumes
   the same object. *Fallback if that refactor is judged too invasive:*
   translate `GenericChart::values()` inside the Docker driver. Quicker, but
   couples Docker to a third-party chart's value schema.
6. **Secrets in v1 are env vars injected at container create.** Docker has no
   Secret object outside Swarm; they are visible to `docker inspect` on a host
   the admin already controls. Documented limitation.
7. **Storage is unmetered.** Each `persistence-<n>-*` entry becomes a named
   volume on the default `local` driver, labelled with the org and app. No
   size is applied, nothing is reported, and the plan/billing flows that
   mention PVC sizes simply have no Docker effect. Volumes are deleted with the
   app, not when it is disabled.

## Reverse proxy strategy

The driver cannot treat routing as an afterthought: it has to put the
container's domains, TLS and redirect domains somewhere a proxy can see them,
and keep that in sync when containers are created, recreated, disabled or
deleted. Different proxies want that in two different shapes, so the driver
defines a small strategy interface instead of baking in Traefik.

```
Docker/Proxy/ReverseProxy.php          interface
Docker/Proxy/TraefikProxy.php          label-driven (Docker provider)
Docker/Proxy/CaddyDockerProxy.php      label-driven (caddy-docker-proxy)
Docker/Proxy/CaddyApiProxy.php         push-driven (Caddy admin API)
Docker/Proxy/NullProxy.php             no managed proxy
```

Two shapes the interface must cover:

| Shape | Examples | How routes reach the proxy | What the driver does |
|---|---|---|---|
| **Label-driven** | Traefik, caddy-docker-proxy | The proxy watches the Docker daemon and reads labels on the container | `labels(RouteSpec): array` merged into the container's labels at create time; nothing to clean up on delete |
| **Push-driven** | Caddy admin API, nginx with generated config, an external proxy | KumuliCP tells the proxy about routes explicitly | `sync(RouteSet)` after create/update/delete, `remove(RouteSet)` on delete, idempotent and rerunnable |

Proposed contract (names indicative):

```php
interface ReverseProxy
{
    // Extra labels / network attachment the container needs (label-driven proxies)
    public function containerConfig(RouteSpec $route): array;

    // Push routes to the proxy (push-driven proxies; no-op for label-driven)
    public function sync(RouteSet $routes): void;
    public function remove(RouteSet $routes): void;

    // Redirect domains (www -> apex, old domain -> new), same two shapes
    public function redirects(RouteSpec $route, array $domains): array;

    // Config test: is the proxy present and reachable on this host?
    public function check(): array;
}
```

- `RouteSpec`: hostnames (`app_instance->domain()` plus aliases), upstream
  container and port, TLS on/off, redirect domains, enabled flag. A disabled
  app produces **no route**, matching `GenericChart`, which turns ingress off
  when the app is disabled so it is not exposed while its data is kept.
- Selected per server by `settings.docker_proxy`
  (`traefik` | `caddy_docker` | `caddy_api` | `none`), default `traefik`.
  Proxy-specific settings are namespaced: `docker_traefik_network`,
  `docker_traefik_entrypoint`, `docker_traefik_cert_resolver`,
  `docker_caddy_admin_url`, and so on. `DockerProfile::defaultSettings()`
  pre-fills them.
- **TLS** is the proxy's job (Traefik cert resolver, Caddy automatic HTTPS).
  The driver only says "TLS on" and supplies the resolver name where one is
  needed.
- **`none`** (an external proxy KumuliCP does not manage): publish the
  container on a host port, record it on the app instance, and surface it in
  the app's status text so an admin can wire it up. This keeps the driver
  usable on hosts where the proxy is owned by someone else.
- **Shared proxy network**: for any managed proxy the container is attached to
  the org network *and* the proxy's network, so only the proxy reaches
  containers across orgs.
- v1 ships `TraefikProxy` and `NullProxy`. `CaddyDockerProxy` and `CaddyApiProxy`
  land in phase 4 (they exist mostly to prove the abstraction covers both
  shapes). Anything else, such as nginx-proxy or HAProxy, is a new class
  implementing the interface.

## Phases

### Phase 0 - spike and decisions (half a day)
- Pin a minimum Engine API version (e.g. v1.41) and send versioned paths.
- Add a `docker:dind` service plus Traefik to `docker-compose.yml.extras`
  (mirrors the existing `k3s` service) so every later phase has a real daemon.
- Confirm the remaining open questions below.

### Phase 1 - skeleton, registration, config test (1-2 days)
New, under `app/Integrations/ServerManagers/Docker/`:

```
Docker/
  Docker.php                  base class: org/server context, api(), network name,
                              container naming (mirrors Kubernetes\Kubernetes)
  DockerProfile.php           description(), interface(), configTest(), defaultSettings()
  DockerConfigTest.php        ping + version, create/remove a throwaway network,
                              volume and container, plus proxy->check(); always cleans up
  Interfaces/DockerWebInterface.php
  Support/DockerApiClient.php           get/post/delete, error normalisation, version prefix
  Support/DockerCredentialContext.php   TLS files, unix socket, per-call temp files
  API/Network.php                       org "namespace" lifecycle
  Proxy/ReverseProxy.php, NullProxy.php   the strategy contract and the no-op
```

Wiring:
- `ServerInterfaceService`: add `'docker' => DockerProfile::class` under `web`.
- Lang: `admin.servers.docker.*` (host, address, ca_cert, api_key, api_secret,
  ip, settings, test_* messages) in `en` and `es`; `messages.api.docker.log.*`.
- `Admin/Servers.php` and `ServerEdit.vue` render fields from `description()`,
  so no UI code should be needed. Verify `ca_cert` renders (Kubernetes already
  uses it).
- A trait for the three `OrganizationInterface` methods (network
  exists/create/remove).

Exit: an admin adds a Docker server, presses "test settings", and gets a real
pass/fail. `ServerInterface::connect()` succeeds and creates the org network.

### Phase 2 - deploy the generic app (3-5 days)
- Extract `WorkloadSpec` from `GenericChart` (decision 5). Pure refactor,
  landed on its own first, with `GenericAppChartTest` green and unchanged.
- `Docker/API/Container.php`, `Image.php` (pull with registry auth from the
  version's `imageRegistry` secret), `Volume.php`:
  - `create` (idempotent by label + config hash, recreate on drift), `start`,
    `stop`, `remove`, `inspect`.
  - `state()` mapping to the existing 0/1/2/3 convention: not found / running
    (and healthy if a healthcheck exists) / created, starting or restarting /
    exited non-zero, unhealthy or dead.
  - Resource limits: `250m` becomes `NanoCpus`, `256Mi` becomes `Memory` bytes.
  - Security defaults: no privileged, `no-new-privileges`, drop capabilities,
    `User` from `security-runAsUser`/`fsGroup`, never mount the Docker socket.
- `Docker/Charts/DockerWorkload.php` builds the container config from
  `WorkloadSpec` plus `ReverseProxy::containerConfig()`.
- `Proxy/TraefikProxy.php` (labels for router, service, TLS resolver,
  entrypoint, network).
- `DockerWebInterface` implements `AppInterface`: `exists`, `get`, `isActive`,
  `checkStatus`, `add`, `update`, `delete`, `notFoundMessage`, plus
  `canRecoverStuckRelease()` returning `false`. It must keep the Kubernetes
  interface's behaviours: shared-plan children rebuild the parent
  (`updateSharedParent`), image-registry auth is ensured before deploy,
  failures return the `['status' => 'failed', ...]` shape `assertSuccessful()`
  expects.
- `AppInstanceService`: add a `workloads()` sibling to `charts()` (or have the
  Docker interface ask the profile). `charts()` is unchanged.
- Compatibility: new `docker` flag (label and description in
  `admin.applications.compatibility.*`, added to `compatibilityCatalog()`),
  declared by `GenericAppProfile` only. Confirm an image-only version
  (`image_repo_name`, `port`, `probe-path`) is accepted for a Docker-only
  generic app, since the version form is built around `helm_chart`.

Exit: create, update, disable/enable and delete a generic app on a Docker host
from the UI. It is reachable on its domain through Traefik with TLS. Disable
removes the route but keeps the volume.

### Phase 3 - domains, jobs, status polling (2-3 days)
- **Domains and redirects:** a `Docker\Services\DomainMiddlewareService`
  implementing `updateRedirectDomains()` through `ReverseProxy::redirects()`.
  The Kubernetes one (and the Rancher `UpdateIngressMiddleware` listener on
  `DomainDeleted`) is the template. Label-driven proxies need a recreate to
  change labels, so batch redirect changes into the normal update path.
  `Domain::ipPointsToServer` uses `server->ip`, so DNS checks need no change.
- **Jobs (`JobInterface`):** `runJob()` creates a one-shot container (no
  restart policy), `jobStatus()` maps `State`/`ExitCode` to
  `running|success|failed`, `jobLogs()` reads `/containers/{id}/logs` and
  **demultiplexes** the 8-byte-framed stream. `RunJob` and `RunSecurityScan`
  only call those three methods. `JobChart` is a Kubernetes Job manifest
  builder, so v1 adds a `DockerJobSpec` adapter for the fields the security
  scan needs (image, command, env, mounts).
- **Health:** a probe path cannot become a Docker `HEALTHCHECK`, which runs
  *inside* the container and needs curl or wget in the image. Use
  `AvailabilityService` (HTTP check from KumuliCP) as the "is it up" signal and
  offer `HEALTHCHECK` as an optional configuration field.

### Phase 4 - hardening and the other proxies
- `CaddyDockerProxy` and `CaddyApiProxy`, as proof the strategy interface
  covers both shapes. The push-driven one drives the "reconcile on every
  create/update/delete" path, so its tests are the interesting ones.
- `VersionUpdateChecker` / `ChartCompatibilityChecker`: handle `docker`
  explicitly as "not a chart server" (unknown or not applicable, never an
  exception).
- **Secrets:** consider tmpfs file delivery (or Swarm secrets) to take them
  out of `docker inspect`. If implemented, add `docker` to
  `Chart::webServerDeliversSecrets()`.
- **Drift:** a test that a container removed by hand is detected (state 0) and
  recreated by `update()`, and that a push-driven proxy's routes are re-synced.
- **Security review:** Docker API access is root on the host. Docs recommend a
  dedicated host, mTLS, and optionally `docker-socket-proxy` limited to the
  endpoints the driver uses. Run `/security-review` on the final diff.

## Testing

Follow the existing structure (`tests/Unit/Kubernetes`, `tests/Unit/Rancher`):

- `tests/Unit/Docker/`
  - `DockerApiClientTest`: version prefix, TLS options, unix-socket option,
    error normalisation, temp credential files removed even when the call
    throws (copy the Kubernetes equivalent).
  - `ContainerStateTest`: every Docker state maps to 0/1/2/3.
  - `DockerWorkloadTest`: env, secrets, labels, resource parsing (`250m`, `1`,
    `256Mi`, `1Gi`), volumes, disabled-app means no route.
  - `ProxyContractTest`: one shared suite run against every `ReverseProxy`
    implementation (route created, updated, removed, redirect, disabled).
  - `TraefikProxyTest` (exact labels), later `CaddyApiProxyTest` (Http::fake
    against the admin API).
  - `LogDemuxTest`: framed log stream parsing, including partial frames.
- Feature tests: a Docker-server variant of the activate, deactivate, delete
  and shared-plan cases using `Http::fake` against the Engine API;
  `tests/Feature/Domains/DockerDomainTest` modelled on `RancherDomainTest`.
- Harness: add `docker` to the `server_manager_drivers` dataset in
  `tests/Pest.php` and to `TestsWithServerInterfaces` / the `SERVER_MANAGER`
  switch, so shared lifecycle tests can run against it. `FakeServerManager`
  already implements the same contracts. Add a Docker state to
  `database/factories/ServerFactory.php`.
- Refactor guard: `GenericAppChartTest` and `ChartLabelsTest` pass unchanged
  across the `WorkloadSpec` extraction.
- Real daemon: `docs/testing-docker-driver.md` (dind + Traefik from
  `docker-compose.yml.extras`), modelled on `testing-kubernetes-driver.md`.

## Documentation

- New `docs/testing-docker-driver.md`.
- Update `docs/server-drivers.md`: three drivers, a "how Docker differs"
  table (no Helm, proxy strategy instead of Ingress objects, network instead
  of namespace). The "must not be renamed" note stays true.
- A short admin page on preparing a Docker host: enabling the TLS endpoint,
  generating client certs, running the chosen proxy, firewalling 2376, and
  what each `docker_*` setting means.

## Non-goals

- Storage limits, quotas or usage reporting for volumes.
- A Portainer (or any other Docker UI) integration.
- Nextcloud, WordPress and CiviCRM Standalone on Docker. They depend on Helm
  chart internals, multi-container releases, hook jobs and chart-specific Job
  manifests. Each would be separate work, likely a multi-container
  `WorkloadSpec`.
- Swarm, multi-host scheduling, autoscaling, more than one replica behind a
  load balancer.
- Volume backups, SSH transport, rootless Docker specifics.
- Installing or upgrading the proxy itself. The driver manages routes on a
  proxy the admin has set up, and checks it in the config test.
- Building images; the driver only pulls.

## Risks

| Risk | Mitigation |
|---|---|
| Docker API access is root on the host | mTLS only, dedicated host, optional socket proxy, never expose the socket to tenant containers, prominent docs, security review |
| `WorkloadSpec` refactor regresses Helm output | Land it first as a pure refactor; `GenericAppChartTest` and `ChartLabelsTest` unchanged |
| Proxy abstraction fits Traefik too closely and breaks on the second proxy | Contract written around both shapes from the start; shared `ProxyContractTest`; Caddy implementations in phase 4 as the check |
| Push-driven proxies can drift from container state | `sync()` is idempotent and also called from `update()`, with a drift test |
| Label changes need container recreation (redirects, enable/disable) | Batch into the normal update path; config-hash label keeps it idempotent |
| Secrets visible in `docker inspect` | Documented; tmpfs/Swarm upgrade path |
| Unmetered volumes can fill the host disk | Accepted explicitly (non-goal); document that capacity monitoring is the host admin's job |
| Image pulls are slow | Use the existing long queue and poll `checkStatus()`, as the Helm path does |
| Scope creep into the Helm-only apps | Non-goals above; the `docker` compatibility flag is the visible gate |

## Open questions

1. **Transport:** TCP+TLS only, or also the local unix socket for
   single-host installs? (Recommended: both; small cost.)
2. **Which proxies must v1 support?** The plan ships Traefik and "none"
   (publish a host port) in v1 and adds the two Caddy implementations in
   phase 4. If you already run a specific proxy (nginx-proxy, Caddy), move it
   up and ship it in place of Traefik.
3. **Generic-app-only for v1, or is Docker only worth it with Nextcloud and
   WordPress?** That changes the estimate from about 2-3 weeks to about a
   quarter.

## Rough size

Phases 1-3 for the generic app on Traefik: about 2-3 weeks for one engineer,
most of it in phase 2 (the `WorkloadSpec` extraction and container
reconciliation). Phase 4, with the two Caddy implementations and hardening,
adds roughly a week.
