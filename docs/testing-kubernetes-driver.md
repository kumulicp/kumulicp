# Testing the direct Kubernetes server manager driver

This driver (`interface = kubernetes`) talks to the Kubernetes API directly
against any standard Kubernetes cluster, with no Rancher dependency. No
cluster tooling needs to be installed where kumulicp runs: resources are
managed over the REST API, release state is read from Helm's own release
Secrets, and installs/upgrades/uninstalls run inside the cluster as a Job
(the `HELM_RUNNER_IMAGE` image). Use the `k3s` service in
`docker-compose.yml.extras` to exercise it end to end against a real cluster
running alongside Sail.

## 1. Spin up the cluster

Copy the `k3s` service (and, optionally, the `headlamp`/`headlamp-kubeconfig`
services for a web UI into the cluster) from `docker-compose.yml.extras` into
your `docker-compose.yml`, then bring it up:

```sh
docker compose up -d k3s
```

Cluster-admin access is only needed for this one-time bootstrap, and the
`k3s` container (`k-k3s-1`) already has it. From a shell in that container
(`docker exec -it k-k3s-1 sh`):

1. Apply `docs/k8s-rbac-sample.yaml` (copy it in with `docker cp`). This
   creates a scoped `kumulicp-deployer` ServiceAccount, not cluster-admin.
2. Issue a long-lived token for that ServiceAccount in the `kumulicp-system`
   namespace (TokenRequest API, or a Secret of type
   `kubernetes.io/service-account-token`). This is `$TOKEN`.
3. Read the cluster's CA certificate (PEM) from the container's kubeconfig
   (`certificate-authority-data`, base64-decoded). This is `$CA_CERT`.

The API server address is the **compose service name**, not `localhost` —
kumulicp runs inside the `laravel.test` container and reaches `k3s` over the
`sail` Docker network, not through the host port mapping:

```sh
API_SERVER=https://k3s:6443
```

`laravel.test` needs nothing extra installed — it only has to reach the API
server over the network.

## 2. Register a Server in the control panel

Admin > Servers > Add Server, type `web`, interface `kubernetes`. On the edit
page, fill in (see `KubernetesProfile::description()` for the full field
mapping — this driver reuses the generic Server fields rather than adding
dedicated columns):

- **Address**: `$API_SERVER` (`https://k3s:6443`)
- **CA Certificate**: `$CA_CERT`
- **Api Secret**: `$TOKEN` (the ServiceAccount token — this field holds the
  bearer token when `k8s_auth_type` is `bearer_token`)
- **Settings** (JSON): `{"k8s_auth_type": "bearer_token"}`
- **Host**/**IP**/**Internal Address**: not used by this driver, any value
  is fine

## 3. Manual smoke test

1. Create an Organization and assign it to the new server.
2. Deploy Nextcloud through the normal UI flow.
3. Verify with cluster-admin access (from the `k3s` container, or Headlamp),
   which is independent of what the driver itself can see:
   - The organization's namespace exists.
   - The namespace has a Secret named `sh.helm.release.v1.*` (label
     `owner=helm`) with `status=deployed` (this is what the driver reads for
     release status).
   - The app's pods are running.
   - The ingress (if domains are configured) resolves. `k3s`'s Traefik is
     published on the host at `80`/`443` — if `laravel.test` is also bound to
     host port `80`, move it first (`APP_PORT` in `.env`) to avoid a
     conflict. `AppInstance::address()`/`domain()` build URLs assuming
     ingress terminates on standard ports, so app URLs won't include a port.
   - Optional: browse the cluster in Headlamp at `http://localhost:8091` if
     you brought up the `headlamp`/`headlamp-kubeconfig` services too.
4. Delete the app instance and confirm the uninstall Job ran in the
   namespace and the release's `owner=helm` Secrets are gone.

### Resetting the cluster

If you wipe the `k3s-server` volume (`docker compose down -v` / `docker
volume rm`), the cluster's CA and signing key are regenerated, which
invalidates the RBAC bootstrap, the Server's stored CA cert, and the token —
even if the namespace/RBAC objects look like they still exist from an old
volume. Re-run step 1 (apply the RBAC sample, issue a token) and update the
Server record with the fresh `CA_CERT`/`TOKEN` afterward.

Also pin the node name (`command: server ... --node-name=k3s` in the `k3s`
service) before resetting — without it, every container recreate registers
as a brand-new node in the persisted datastore, leaving stale `NotReady`
nodes and stuck `Terminating` pods behind.

## 4. Automated tests

Unit tests for the API layer (`KubernetesApiClient`, `HelmReleases`,
`K8sCredentialContext`) use `Illuminate\Support\Facades\Http::fake()` and
don't require a real cluster — see `tests/Unit/Kubernetes/`. A real-cluster
feature test is not included by default; wire one up against the `k3s`
compose service (or a CI-provisioned cluster) if you want full end-to-end
coverage in CI.
