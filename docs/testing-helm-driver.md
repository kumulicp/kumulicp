# Testing the direct helm/kubectl server manager driver

This driver (`interface = helm_k8s`) talks to `helm` and `kubectl` directly
against any standard Kubernetes cluster, with no Rancher dependency. Use a
local [k3d](https://k3d.io) cluster to exercise it end to end.

## 1. Spin up a local cluster

```sh
k3d cluster create kumulicp-dev
kubectl apply -f docs/k8s-rbac-sample.yaml
TOKEN=$(kubectl create token kumulicp-deployer -n kumulicp-system --duration=87600h)
API_SERVER=$(kubectl config view --raw --minify -o jsonpath='{.clusters[0].cluster.server}')
CA_CERT=$(kubectl config view --raw --minify -o jsonpath='{.clusters[0].cluster.certificate-authority-data}' | base64 -d)
```

## 2. Register a Server in the control panel

Admin > Servers > Add Server, type `web`, interface `helm_k8s`. On the edit
page, fill in:

- **Kubernetes API Server**: `$API_SERVER`
- **Cluster CA Certificate**: `$CA_CERT`
- **Authentication Method**: Bearer Token
- **ServiceAccount Token**: `$TOKEN`

Requires the `helm` and `kubectl` binaries to be on `$PATH` for the process
running the control panel (or set `HELM_BINARY_PATH`/`KUBECTL_BINARY_PATH`).

## 3. Manual smoke test

1. Create an Organization and assign it to the new server.
2. Deploy Nextcloud through the normal UI flow.
3. Verify:
   - `kubectl get ns` shows the organization's namespace.
   - `helm list -n <namespace>` shows the release as `deployed`.
   - `kubectl get pods -n <namespace>` shows running pods.
   - The ingress (if domains are configured) resolves.
4. Delete the app instance and confirm `helm uninstall` ran and the release
   is gone (`helm list -n <namespace>`).

## 4. Automated tests

Unit tests for the CLI-invocation layer (`HelmCli`, `KubectlCli`,
`K8sCredentialContext`) use `Illuminate\Support\Facades\Process::fake()` and
don't require a real cluster — see `tests/Unit/HelmKubernetes/`. A real-cluster
feature test is not included by default; wire one up against a CI-provisioned
k3d cluster if you want full end-to-end coverage in CI.
