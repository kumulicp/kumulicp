# Testing the direct helm/kubectl server manager driver

This driver (`interface = helm_k8s`) talks to `helm` and `kubectl` directly
against any standard Kubernetes cluster, with no Rancher dependency. Use the
`k3s` service in `docker-compose.yml.extras` to exercise it end to end
against a real cluster running alongside Sail.

## 1. Spin up the cluster

Copy the `k3s` service (and, optionally, the `headlamp`/`headlamp-kubeconfig`
services for a web UI into the cluster) from `docker-compose.yml.extras` into
your `docker-compose.yml`, then bring it up:

```sh
docker compose up -d k3s
```

`k3s` doesn't have its own `kubectl`/`helm`, so run cluster-admin commands
via `docker exec`. Apply the sample RBAC (a scoped `kumulicp-deployer`
ServiceAccount, not cluster-admin) and pull a token/CA cert for it:

```sh
docker cp docs/k8s-rbac-sample.yaml k-k3s-1:/tmp/k8s-rbac-sample.yaml
docker exec k-k3s-1 kubectl apply -f /tmp/k8s-rbac-sample.yaml

TOKEN=$(docker exec k-k3s-1 kubectl create token kumulicp-deployer -n kumulicp-system --duration=87600h)
CA_CERT=$(docker exec k-k3s-1 kubectl config view --raw --minify -o jsonpath='{.clusters[0].cluster.certificate-authority-data}' | base64 -d)
```

The API server address is the **compose service name**, not `localhost` —
kumulicp runs inside the `laravel.test` container and reaches `k3s` over the
`sail` Docker network, not through the host port mapping:

```sh
API_SERVER=https://k3s:6443
```

`laravel.test`'s image already has `helm`/`kubectl` installed (see
`docker/8.4/Dockerfile`), so no extra setup is needed there.

## 2. Register a Server in the control panel

Admin > Servers > Add Server, type `web`, interface `helm_k8s`. On the edit
page, fill in (see `HelmKubernetesProfile::description()` for the full field
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
3. Verify (via `docker exec k-k3s-1 kubectl ...`/`helm ...`, or `sail exec
   laravel.test kubectl ...`/`helm ...` to check exactly what the driver
   itself sees):
   - `kubectl get ns` shows the organization's namespace.
   - `helm list -n <namespace>` shows the release as `deployed`.
   - `kubectl get pods -n <namespace>` shows running pods.
   - The ingress (if domains are configured) resolves. `k3s`'s Traefik is
     published on the host at `80`/`443` — if `laravel.test` is also bound to
     host port `80`, move it first (`APP_PORT` in `.env`) to avoid a
     conflict. `AppInstance::address()`/`domain()` build URLs assuming
     ingress terminates on standard ports, so app URLs won't include a port.
   - Optional: browse the cluster in Headlamp at `http://localhost:8091` if
     you brought up the `headlamp`/`headlamp-kubeconfig` services too.
4. Delete the app instance and confirm `helm uninstall` ran and the release
   is gone (`helm list -n <namespace>`).

### Resetting the cluster

If you wipe the `k3s-server` volume (`docker compose down -v` / `docker
volume rm`), the cluster's CA and signing key are regenerated, which
invalidates the RBAC bootstrap, the Server's stored CA cert, and the token —
even if the namespace/RBAC objects look like they still exist from an old
volume. Re-run step 1's `kubectl apply`/token commands and update the Server
record with the fresh `CA_CERT`/`TOKEN` afterward.

Also pin the node name (`command: server ... --node-name=k3s` in the `k3s`
service) before resetting — without it, every container recreate registers
as a brand-new node in the persisted datastore, leaving stale `NotReady`
nodes and stuck `Terminating` pods behind.

## 4. Automated tests

Unit tests for the CLI-invocation layer (`HelmCli`, `KubectlCli`,
`K8sCredentialContext`) use `Illuminate\Support\Facades\Process::fake()` and
don't require a real cluster — see `tests/Unit/HelmKubernetes/`. A real-cluster
feature test is not included by default; wire one up against the `k3s`
compose service (or a CI-provisioned cluster) if you want full end-to-end
coverage in CI.
