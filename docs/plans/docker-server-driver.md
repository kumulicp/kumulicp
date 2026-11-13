# Plan: Docker web server driver (and an optional Portainer layer)

Status: proposal. Nothing here is implemented.

## Goal

Let an admin register a plain Docker host as a **web server** (`type = web`,
`interface = docker`) and deploy KumuliCP apps onto it, the same way they can
today onto a Kubernetes cluster (`kubernetes`) or a Rancher-managed one
(`rancher`).

The existing layering is *cluster API → manager on top of it*:

```
kubernetes  (base driver, talks to the Kubernetes API)
  └─ rancher   (extends it, talks to Rancher's API)
```

The Docker equivalent is:

```
docker      (base driver, talks to the Docker Engine API)
  └─ portainer (phase 5, optional: extends it, talks to Portainer's API,
                which proxies the Engine API of an "environment")
```

So "like Portainer" is read as: Portainer is to Docker what Rancher is to
Kubernetes. Phases 1–4 build the base `docker` driver; Portainer is a thin
follow-up. If a Portainer-only driver was the intent, phase 5 can be pulled
forward without changing anything else.

## What the code looks like today (the constraints)

| Fact | Where | Consequence |
|---|---|---|
| Drivers are registered by name in a map | `app/Services/ServerInterfaceService.php` (`$interfaces['web']`) | One line to register `docker`. The admin UI's interface dropdown comes from `ServerInterface::all('web')` (`Admin/Servers.php`). |
| A driver is a *Profile* (form fields + `interface()` + optional `configTest()`) and a *WebInterface* | `Kubernetes/KubernetesProfile.php`, `Rancher/RancherProfile.php` | Same shape for `DockerProfile` / `DockerWebInterface`. |
| The web interface implements `AppInterface`, `JobInterface`, `OrganizationInterface` | `app/Contracts/ServerManager/*`, `app/Contracts/OrganizationInterface.php` | These contracts are already driver-neutral. Docker implements the same three. |
| Callers always go through `$app_instance->connect('web')` / `$org_server->connect()` | `ServerInterfaceService::connect()` (calls `existsOrganization()` and `addOrganization()` on first connect) | No caller outside the driver changes, as long as the contracts are honoured. |
| **App deployment is Helm-chart shaped.** `AppInstanceService::charts()` only returns `HelmChart` instances; `KubernetesWebInterface::add/update/delete` loop over them | `app/Services/AppInstanceService.php:69`, `Kubernetes/Interfaces/KubernetesWebInterface.php` | This is the real seam. Docker needs a non-Helm description of "what to run". |
| Only the `generic` app is image-based. Nextcloud, WordPress and CiviCRM are Helm charts with chart-specific values and Job charts | `GenericAppProfile`, `NextcloudChart`, `WordpressChart`, `CiviCRMStandaloneChart` | Docker v1 supports the generic app only. The others stay `kubernetes`/`rancher`-only (see Non-goals). |
| `GenericChart::values()` already holds everything a single-container deployment needs: image, env, resources, PVC-backed volumes, one ingress host, probe path, run-as user, replica count | `Kubernetes/Charts/GenericChart.php` | Source of truth to share, not duplicate. |
| Compatibility flags (`rancher`, `helm_chart`, …) gate what an app can do | `AppProfile::compatibilityCatalog()`, `Admin/Applications/Versions.php:185` | Add a flag so admins can see which apps can run on Docker. |
| `Chart::webServerDeliversSecrets()` hard-codes `['kubernetes','rancher']` | `Kubernetes/Charts/Chart.php` | Docker is *not* in this list at first, so secrets fall back to plain env values. Revisit in phase 4. |
| `VersionUpdateChecker` / `ChartCompatibilityChecker` branch on `interface === 'rancher' \| 'kubernetes'` | `app/Support/VersionUpdates/*` | Must treat `docker` as "not applicable" rather than failing. |
| Redirect domains use Traefik Middleware + Ingress objects | `Kubernetes/Services/DomainMiddlewareService.php` | Docker equivalent is Traefik **labels** on the container. |
| `RunJob` action slug `run_rancher_job` / group `rancher` is stored on task rows | `ActionService.php:107`, `docs/server-drivers.md` | Do not rename. Docker reuses it through `JobInterface`. |
| Docker is already present as a dev tool (`docker-compose.yml.extras`, `docker/*`) and `DockerMailServer` is an *email* driver | repo root | Name the namespace `Docker/`; no clash with `DockerMailServer/`. |

## Design decisions (recommendations, each can be overridden)

1. **Talk to the Docker Engine API directly over HTTP(S)**, mirroring how the
   Kubernetes driver uses the REST API with no CLI on the app host. Laravel's
   `Http` client, no `docker` binary, no new composer dependency.
   - Transport: TCP+TLS with client certificate (`https://host:2376`) as the
     primary mode. Unix socket (`unix:///var/run/docker.sock`, via the curl
     `CURLOPT_UNIX_SOCKET_PATH` option) as a second mode for same-host
     installs. SSH tunnelling is out of scope.
   - Credentials reuse `servers` columns, exactly like `K8sCredentialContext`:
     `address` = endpoint, `ca_cert` = CA PEM, `api_key` = client cert,
     `api_secret` = client key (both already encrypted + hidden). Temp files
     are 0600, per-call, always deleted. No migration needed; per-server knobs
     go in `settings`.
2. **One Server = one Docker host.** Placement across hosts is already
   expressed by plans pointing at a web server; no scheduler is built.
3. **"Namespace" = a per-organization Docker network** (`kumulicp-<org-slug>`)
   plus a name prefix. `existsOrganization()` / `addOrganization()` /
   `deleteOrganization()` create and remove that network. Org isolation is
   network-level; containers of different orgs share no network.
4. **Plain Engine containers, not Compose or Swarm.** KumuliCP is already the
   reconciler and already stamps `kumulicp.io/{app,instance,type}` labels
   (`Chart::labels()`), so containers are found by label, not by a compose
   project file. Update strategy is *recreate* (matches `GenericChart` when it
   has volumes): pull → create new → stop/remove old → start. Swarm services
   are a possible later driver; Portainer users often run Swarm, so keep the
   resource layer behind small classes so a `swarm` variant can override them.
5. **Ingress via Traefik labels.** Requires Traefik on the Docker host
   attached to a shared network. Server `settings`: `traefik_network`,
   `traefik_entrypoint`, `traefik_cert_resolver`. TLS is a cert-resolver
   label, not cert-manager. Redirect domains become redirect-middleware
   labels on the same container.
6. **Introduce a runtime-neutral workload description** rather than
   translating Helm values. Extract a `WorkloadSpec` value object from
   `GenericChart` (image, tag, pull policy, registry auth, env, secret env,
   resource limits, volumes, port, probe path, run-as user/group, replicas,
   hosts, TLS, labels). `GenericChart::values()` is rewritten to consume it
   with **byte-identical output** (guarded by `GenericAppChartTest`), and
   `DockerWorkload` consumes the same object. This removes the
   `charts()`/`HelmChart` coupling for Docker without touching Helm behaviour.
   *Fallback if that refactor is judged too invasive:* translate
   `GenericChart::values()` to container config inside the Docker driver. It
   is quicker but couples Docker to a third-party chart's value schema.
7. **Secrets in v1 are env vars injected at container create.** Docker has no
   Secret object outside Swarm. They are visible to `docker inspect` on the
   host, which the admin already controls. Documented as a known limitation;
   Swarm secrets or a tmpfs file mount are the upgrade path.

## Phases

### Phase 0 — spike and decisions (½ day)
- Confirm the target Engine API version (pin a minimum, e.g. v1.41; the client
  sends a versioned path so older/newer daemons behave predictably).
- Stand up `docker:dind` plus Traefik in `docker-compose.yml.extras` (mirrors
  the existing `k3s` service) so every later phase has a real daemon to hit.
- Settle the open questions below.

### Phase 1 — skeleton, registration, config test (1–2 days)
New, under `app/Integrations/ServerManagers/Docker/`:

```
Docker/
  Docker.php                  base class: org/server context, api(), network name,
                              container naming (mirrors Kubernetes\Kubernetes)
  DockerProfile.php           description() form fields, interface(), configTest()
  DockerConfigTest.php        ping + version, create/remove a throwaway network,
                              volume and container, always cleaned up (like RancherConfigTest)
  Interfaces/DockerWebInterface.php
  Support/DockerApiClient.php      get/post/delete, error normalisation, version prefix
  Support/DockerCredentialContext.php   TLS files, unix socket, per-call temp files
  API/Network.php             org "namespace" lifecycle
```

Wiring:
- `ServerInterfaceService`: add `'docker' => DockerProfile::class` under `web`.
- `DockerProfile` may define `defaultSettings()` (the `Admin/Servers.php`
  `store()` calls it when present) to pre-fill `traefik_network`,
  `traefik_entrypoint` and `traefik_cert_resolver`. Follow the existing
  per-server settings naming (`traefik_middleware`, `storage_class`,
  `k8s_*`), using a `docker_*` prefix for Docker-specific keys.
- Lang: `admin.servers.docker.*` (host, address, ca_cert, api_key, api_secret,
  ip, settings, test_* messages) in `en` and `es`; `messages.api.docker.log.*`.
- `Admin/Servers.php` and `ServerEdit.vue` already render fields from
  `description()`; verify the new keys appear, and that `ca_cert` renders (the
  Kubernetes driver already uses it).
- `OrganizationServices`-equivalent trait for the three
  `OrganizationInterface` methods (network exists/create/remove).

Exit: an admin can add a Docker server, press "test configuration", and see a
real pass/fail. `ServerInterface::connect()` succeeds and creates the org
network.

### Phase 2 — deploy the generic app (3–5 days)
- Extract `WorkloadSpec` from `GenericChart` (design decision 6). Pure
  refactor, lands as its own commit/PR first, with `GenericAppChartTest`
  green and unchanged.
- `Docker/API/Container.php`, `Image.php` (pull with registry auth from the
  version's `imageRegistry` secret), `Volume.php`:
  - `create` (idempotent: find by label, compare a config hash label, recreate
    on drift), `start`, `stop`, `remove`, `inspect`.
  - `state()` mapping to the existing 0/1/2/3 convention: not found / running
    (and healthy if a healthcheck exists) / created-starting-restarting /
    exited non-zero-unhealthy-dead.
  - Resource limits: map `250m` → `NanoCpus`, `256Mi` → `Memory` bytes.
  - Security defaults: no privileged, `no-new-privileges`, drop capabilities,
    `User` from `security-runAsUser`/`fsGroup`, never mount the Docker socket.
- `Docker/Charts/DockerWorkload.php` builds the container config from
  `WorkloadSpec` (env, labels, mounts, Traefik labels, restart policy
  `unless-stopped`, network attachment).
- `DockerWebInterface` implements `AppInterface`: `exists`, `get`,
  `isActive`, `checkStatus`, `add`, `update`, `delete`, `notFoundMessage`,
  plus `canRecoverStuckRelease()` returning `false` (nothing to recover).
  Honour the same behaviours as the Kubernetes interface: shared-plan
  children rebuild the parent (`updateSharedParent`), `ensureImageRegistryAuth`
  equivalent, disabled app = no routing labels, and return the
  `['status' => 'failed', ...]` style result that `assertSuccessful()` expects.
- `AppInstanceService`: add a `workloads()` sibling to `charts()` (or have the
  Docker interface call the profile directly). `charts()` stays as is.
- Compatibility: new flag `docker` (label + description in
  `admin.applications.compatibility.*`, added to `compatibilityCatalog()`),
  declared by `GenericAppProfile` only. The version form shows chart fields
  only for `helm_chart`, so confirm an image-only version is accepted for a
  Docker-only generic app (`image_repo_name`, `port`, `probe-path`).

Exit: create, update, disable/enable, delete a generic app on a Docker host
from the UI; the container is reachable through Traefik with TLS; delete
removes containers and the org's volumes only when the app is deleted, not
on disable.

### Phase 3 — domains, jobs, status polling (2–3 days)
- **Domains/redirects:** a `Docker\Services\DomainMiddlewareService` that
  applies redirect labels and implements `updateRedirectDomains()` for the
  interface (the Kubernetes one is the template, including the Rancher
  `UpdateIngressMiddleware` listener equivalent if redirects change without a
  redeploy; changing container labels requires a recreate, so batch it).
  `Domain::ipPointsToServer` uses `server->ip`, so no change.
- **Jobs (`JobInterface`):** `runJob()` creates a one-shot container (no
  restart policy, optional auto-remove after logs are read), `jobStatus()`
  maps `State`/`ExitCode` to `running|success|failed`, `jobLogs()` reads
  `/containers/{id}/logs` and **demultiplexes** the 8-byte-framed stream (a
  classic Docker gotcha). `RunJob` and `RunSecurityScan` already only call
  those three methods. `JobChart` is a Kubernetes Job manifest builder, so
  v1 adds a `DockerJobSpec` adapter for the fields the security-scan job
  needs (image, command, env, mounts); the Nextcloud/WordPress job charts
  stay out of scope with their apps.
- **Availability / health:** a probe path can't become a Docker `HEALTHCHECK`
  because it executes *inside* the container and many images have no
  curl/wget. Use `AvailabilityService` (HTTP check from KumuliCP) for the
  "is it up" signal and keep Docker `HEALTHCHECK` as an optional configuration
  field for images that support it.

### Phase 4 — hardening, parity gaps, and the places that branch on interface
- `VersionUpdateChecker` / `ChartCompatibilityChecker`: handle `docker`
  explicitly as "not a chart server" (UNKNOWN/not-applicable, never an
  exception). Optional later feature: image-tag update checks against the
  registry.
- **Storage:** Docker's default `local` volume driver can't enforce a size.
  Decide between (a) report-only usage, (b) xfs project quotas /
  size-limited volume driver options as a server setting, (c) disallow
  storage-limited plans on Docker servers. Plans and billing that assume a
  PVC size need a visible rule here.
- **Backups:** the existing backup flow targets databases/backup servers, not
  PVCs. Document that Docker volumes are *not* covered unless that already
  holds for Kubernetes PVCs; if it does, add a volume export job in a
  follow-up.
- **Secrets:** evaluate tmpfs file delivery or Swarm secrets to take them out
  of `docker inspect`. If implemented, add `docker` to
  `Chart::webServerDeliversSecrets()`.
- **Reconciliation / drift:** an `UpdateDriftTest`-style check that a
  container removed by hand is detected (state 0) and recreated by `update()`.
- **Security review:** Docker API access is root on the host. Docs must
  recommend a dedicated host, mTLS, and optionally `docker-socket-proxy`
  limited to the endpoints the driver uses. Add a `/security-review` pass on
  the final diff.

### Phase 5 — Portainer driver (optional, 2–4 days)
Mirrors how `Rancher` extends `Kubernetes`:

- `Portainer/` extends the Docker classes. It overrides only the client and
  credentials: base URL `…/api/endpoints/{endpoint_id}/docker`, auth header
  `X-API-Key` (token in `api_secret`), `endpoint_id` in `settings`
  (`portainer_endpoint_id`). Portainer proxies the Engine API, so most
  resource classes need no override.
- Register `'portainer' => PortainerProfile::class`; add `PortainerConfigTest`
  (token valid, endpoint reachable, can create/remove a network).
- Optional Portainer-only extras behind overrides: resource-control /
  ownership so tenant containers are visible to the right Portainer team, and
  Portainer stacks if Swarm/Compose management is ever wanted.
- Update `docs/server-drivers.md` (layout, "How X differs") accordingly.

## Testing

Follow the existing structure (`tests/Unit/Kubernetes`, `tests/Unit/Rancher`):

- `tests/Unit/Docker/`
  - `DockerApiClientTest`: URL/version prefix, TLS options, unix-socket
    option, error normalisation, temp credential files removed even on throw
    (copy the equivalent Kubernetes test).
  - `ContainerStateTest`: every Docker state → 0/1/2/3.
  - `DockerWorkloadTest`: env, secrets, labels, Traefik labels, resource
    parsing (`250m`, `1`, `256Mi`, `1Gi`), volumes, disabled-app routing.
  - `LogDemuxTest`: framed log stream parsing, including partial frames.
  - `ExtensionPointsTest`: the Portainer override seams (phase 5).
- Feature tests: `tests/Feature/Applications/*` add a Docker-server variant of
  the activate/deactivate/delete/shared-plan cases using `Http::fake` against
  the Engine API; `tests/Feature/Domains/DockerDomainTest` modelled on
  `RancherDomainTest`.
- Refactor guard: `GenericAppChartTest` must pass unchanged across the
  `WorkloadSpec` extraction.
- Real-daemon run: `docs/testing-docker-driver.md` (dind + Traefik service in
  `docker-compose.yml.extras`), modelled on `testing-kubernetes-driver.md`.
- Add Docker to `database/factories/ServerFactory.php` as a state.
- Test harness: `tests/Support/Concerns/TestsWithServerInterfaces.php` and the
  `server_manager_drivers` dataset in `tests/Pest.php` only know `fake` and
  `rancher` (the fake is registered over `('web','rancher')`, and
  `restoreServerInterfaces()` restores `RancherProfile`). Extend the dataset
  and the `SERVER_MANAGER` env switch with `docker`, so the shared lifecycle
  tests can run against it. `FakeServerManager` already implements the same
  three contracts, so it needs no change.

## Documentation

- New `docs/testing-docker-driver.md`.
- Update `docs/server-drivers.md`: three drivers instead of two, the "how
  Docker differs" table (no Helm, labels instead of Ingress objects, network
  instead of namespace), and the "things that must not be renamed" note stays
  true.
- A short admin-facing page on preparing a Docker host: enabling the TLS
  endpoint, generating client certs, installing Traefik, firewalling 2376.

## Non-goals (v1)

- Nextcloud, WordPress and CiviCRM Standalone on Docker. They depend on Helm
  chart internals, multi-container releases, hook jobs and chart-specific
  Job manifests. Each would be a separate piece of work (likely a compose-style
  multi-container `WorkloadSpec`).
- Swarm, multi-host scheduling, autoscaling, replicas greater than 1 behind a
  load balancer.
- Docker volume backups, SSH transport, rootless Docker specifics.
- Building images; the driver only pulls.

## Risks

| Risk | Mitigation |
|---|---|
| Docker API = root on the host | mTLS only, dedicated host, optional socket proxy, never expose the socket to tenant containers, documented prominently, security review |
| `WorkloadSpec` refactor regresses Helm output | Land it first as a pure refactor; `GenericAppChartTest` and `ChartLabelsTest` unchanged |
| No storage quotas on `local` volumes | Explicit decision in phase 4 before docker servers can be assigned to storage-limited plans |
| Label changes need container recreation (redirect domains, disable/enable) | Batch label updates into the normal update path; keep the update idempotent via a config-hash label |
| Secrets visible in `docker inspect` | Documented; Swarm/tmpfs upgrade path |
| Laravel queue workers are long-lived; an image pull can be slow | Use the existing long queue and poll `checkStatus()`, as the Helm path does, rather than blocking on pull |
| Scope creep into the Helm-only apps | Non-goals above; the `docker` compatibility flag is the visible gate |

## Open questions

1. **Does "like Portainer" mean a Portainer integration, or a Docker driver
   that offers a Portainer-like capability?** The plan assumes the former is
   optional (phase 5) and the base Engine driver comes first. If Portainer is
   the only way you want to reach Docker, swap the order of phases 1 and 5's
   client, everything else is unchanged.
2. **Which transport must v1 support:** TCP+TLS only, or also the local unix
   socket for single-host installs? (Recommended: both, small cost.)
3. **Is Traefik the required ingress on Docker hosts?** The rest of the code
   already assumes Traefik for redirects. Supporting another proxy (Caddy,
   nginx-proxy) would be a separate ingress strategy.
4. **Should generic-app Docker support ship before multi-container support
   for Nextcloud/WordPress, or is Docker only worth it with those apps?**
   That changes the size of the plan from roughly 2–3 weeks to a quarter.
5. **Storage-limit policy for Docker volumes** (report-only, xfs quotas, or
   block such plans).
6. **Single environment per Portainer server, or many?** (Rancher setup uses
   one `project_id` / `rancher_cluster_id` per server; assume the same.)

## Rough size

Phases 1–4 for the generic app: about 2–3 weeks for one engineer, most of it
in phase 2 (the `WorkloadSpec` extraction and container reconciliation).
Phase 5 adds a few days.
