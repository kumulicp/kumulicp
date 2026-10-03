# Kubernetes security hardening plan

Status: proposal. No code has been changed yet.

Goal: make every organization namespace KumuliCP creates, and every app activated in it, run under Kubernetes
Pod Security Admission (PSA) `restricted`. Roll out by observing first (`warn`/`audit`), fix the workloads,
then enforce. Surface the security settings that app Helm charts and images already offer, and encourage admins
to use them. Namespace-level controls are separate from per-app controls, and a sysadmin who already runs
their own policy engine must be able to opt out of ours.

---

## 1. What exists today (findings)

| Area | Finding | Where |
|---|---|---|
| Namespace creation | The manifest has only `name`. No labels, so no PSA. `update()` is an empty no-op, so existing namespaces never get reconciled. | `app/Integrations/ServerManagers/HelmKubernetes/API/KubernetesNamespace.php` |
| Rancher driver | Sets only the project id label/annotation. Rancher's own Pod Security Admission Configuration Templates (PSACT) apply per project and may conflict with namespace labels. | `.../Rancher/API/KubernetesNamespace.php` |
| Deployer RBAC | `kumulicp-deployer` can only `get/list/create/delete` namespaces. It cannot `patch`/`update`, so relabeling existing namespaces fails until the ClusterRole is extended. | `docs/k8s-rbac-sample.yaml` |
| Chart values | None of `NextcloudChart`, `WordpressChart` or `CiviCRMStandaloneChart` set any `securityContext`/`podSecurityContext`/`containerSecurityContext`. We rely on chart defaults, and the pinned chart versions have not been checked against `restricted`. | `.../Rancher/Charts/*`, `Applications/CiviCRMStandalone/CiviCRMStandaloneChart.php` |
| Helm install Job | `HelmInstallJob::jobManifest()` runs `alpine/helm` with no `securityContext`. It runs as root with a writable root FS and would be rejected the day `enforce` is turned on. | `.../HelmKubernetes/Support/HelmInstallJob.php` |
| Other KumuliCP Jobs | `NextcloudJobChart` (`fsGroup 33`, `runAsUser 33`), `WordpressJobChart` (`fsGroup 0`), `Nextcloud/Commands/RancherJob.php` and `MySQLJobChart` set partial or no hardening. | `.../Rancher/Charts/Job/*` |
| Security scans | `RunSecurityScan` runs the scan Job in the org namespace. `kube-bench` and `kube-hunter` need host-level access and cannot run there under PSA. `polaris`, `trivy`, `kubescape` and `nuclei` can. | `app/Actions/Security/RunSecurityScan.php`, `SecurityScanJobChart.php` |
| Compatibility flags | `AppProfile::$compatibility` is a list of flags. `compatibilityCatalog()` lists the known flags, and `Admin\Applications::show()` renders them as chips in `AppView.vue`. Adding a flag needs a catalog entry plus `applications.compatibility.<flag>.{label,description}` strings in `resources/lang/{en,es}/admin.php`. | `app/Integrations/Applications/AppProfile.php` |
| App configurations | `AppProfile::$configurations` (name/type/default/validations) are mapped into chart values via `$app_instance->configuration('x')`. Plans can also define `additionalConfigs`. `HelmChart::valuesWithAdditionalConfigs()` applies them with the `a-b` → `a.b` key convention. This is the existing hook for exposing chart settings, so no new rendering plumbing is needed. | `HelmChart.php`, `AppPlan::additionalConfigs()` |
| Settings storage | `Server::setting()` and `Server::settingArray()` use a JSON column. `Organization::settings` is also JSON. A policy needs no migration. | `app/Server.php`, `app/Organization.php` |
| Existing tooling | Polaris, Kubescape, Trivy and kube-bench scans, plus `SecurityFinding` storage, already exist. They are a ready verification and regression loop. | `app/Support/Security/Tools/*` |

Consequence: a namespace holds multiple apps plus KumuliCP's own Jobs. The effective PSA level of a
namespace is capped by its least-hardened workload. Policy therefore has to be computed per namespace, not
set globally.

---

## 2. Design

### 2.1 Three layers

1. **Namespace policy** (admin-owned). PSA levels and guardrails applied to each org namespace. Defaults come
   from the Server (cluster). An admin can override per organization.
2. **App security settings** (chart-owned). Whatever the chart exposes (`securityContext`, `networkPolicy`,
   service account token, and so on) is shown as app configuration, with KumuliCP recommending values.
3. **KumuliCP's own workloads** (always hardened). Install Jobs, helper Jobs and scan Jobs are
   `restricted`-conformant by construction, with no toggle.

### 2.2 Ownership mode per Server

`security.mode` in server settings:

- `managed` (default): KumuliCP applies labels and guardrails and reconciles them.
- `observe`: KumuliCP never writes policy. It still runs preflight checks and shows findings. This is for
  admins who use Kyverno, Gatekeeper, ValidatingAdmissionPolicy or Rancher PSACT.
- `off`: nothing.

Chart-level hardening values (layer 2) still apply in `observe` mode, because those are the settings we should
encourage regardless of who enforces.

### 2.3 Policy value object

`App\Support\Security\NamespaceSecurityPolicy`:

```
pod_security: { enforce: null|privileged|baseline|restricted,
                warn:    ..., audit: ...,
                enforce_version: latest|vX.Y, warn_version: latest, audit_version: latest }
on_incompatible_app: block | downgrade | allow     # see 2.4
network_policy: off | default_deny_with_allows
resource_limits: off | from_plan
automount_sa_token: true|false
```

- Defaults: `enforce=null`, `warn=restricted`, `audit=restricted`, versions `latest`.
- Resolution order: Server settings `security.*` → `Organization.settings.security.*` (admin-only exception)
  → clamped by the effective app capability (2.4).
- `labels()` returns `pod-security.kubernetes.io/{enforce,warn,audit}[-version]`. Omit the `enforce` labels
  when the level is null, and pin a version for `enforce` once it is on.

### 2.4 Effective level per namespace

```
effective_enforce = min(policy.enforce, strictest level every active app + KumuliCP Jobs pass)
```

Each app profile declares `max_pod_security`, the highest level it is verified for. Rules:

- Raising `enforce` requires a clean preflight (2.5). The UI blocks it, with an admin override.
- Activating an app whose `max_pod_security` is below the namespace's `enforce` follows
  `on_incompatible_app`: `block` (default, clear error), `downgrade` (relabel + notify), or `allow`.
- Ordering during rollout: harden workloads → verify no `warn` output → only then raise `enforce`.

### 2.5 Getting feedback (the part that makes warn/audit useful)

- `warn` is only visible to the client making the request. Here that is the `helm` process inside the install
  Job, so its warnings land in the Job logs. After install/upgrade, read them with `Pod::logsForJob()`, parse
  `Warning: would violate PodSecurity "restricted:…"`, and attach them to the app instance and Task.
- `audit` goes to the API server audit log, which most admins can't see. Treat it as an admin-side extra, not a
  user-facing signal.
- **Preflight**: `kubectl label --dry-run=server --overwrite ns <ns> pod-security.kubernetes.io/enforce=<level>`
  returns warnings listing every existing pod that would violate. Wrap it as `PodSecurityPreflight` and store
  the result as a `SecurityScan` (tool `pod-security`) with `SecurityFinding` rows, reusing the existing UI.

---

## 3. Phased implementation

### Phase 0 — Baseline and spikes (no behavior change)

- In a `kind`/test cluster (k8s ≥ 1.25), install Nextcloud, WordPress and CiviCRM at the pinned chart versions
  into a namespace labelled `warn=restricted`. Record every violation per app and subchart (MariaDB, Redis,
  imaginary, cron, sidecars).
- Spike each app image as non-root. Known risks:
  - The official Nextcloud image's entrypoint expects root for its rsync/chown install step, and Apache binds
    port 80 (needs `NET_BIND_SERVICE` or a high port).
  - Legacy WordPress Job uses `fsGroup 0`.
  - Document the minimum `runAsUser`/`fsGroup`/ports that work.
- Check which `securityContext` keys each pinned chart actually exposes (`helm show values`). Do not assume
  key names.
- Output: an app × violation matrix and a go/no-go per app for `restricted`.

### Phase 1 — Namespace labels, warn/audit only

- Add `NamespaceSecurityPolicy` and the resolver (Server → Organization).
- `HelmKubernetes\API\KubernetesNamespace`: add labels to `manifest()`, implement `update()` as an idempotent
  re-apply of the labels. Rancher driver: add labels to `values()` and document the PSACT interaction.
- Reconcile existing namespaces: artisan `kumulicp:security:sync-namespaces {--dry-run}`, and re-sync on app
  activate/upgrade/deactivate. Warn/audit-only labels cannot block anything, so backfill is safe.
- RBAC: add `patch`/`update` on `namespaces` to `kumulicp-deployer` in `docs/k8s-rbac-sample.yaml`. Existing
  clusters must re-apply the ClusterRole; call it out in release notes. Add a check to `ValidateServer` so a
  missing permission shows as "labels can't be applied" rather than a silent failure.
- Server edit UI: a "Security" tab with mode, PSA levels and versions, and the default for new namespaces.
- Parse install-Job warnings (2.5) and show them on the app view.

Tests: Pest + `Process::fake` (pattern in `tests/Unit/HelmKubernetes/KubernetesNamespaceTest.php`) for label
rendering, resolution order and `observe` mode writing nothing.

Exit: every managed namespace carries warn/audit `restricted`, and violations per app are visible.

### Phase 2 — Harden what KumuliCP creates itself

- Add a `PodSecurityContexts` helper returning `restricted`-conformant pod and container fragments:
  `runAsNonRoot`, `allowPrivilegeEscalation: false`, `capabilities.drop: ["ALL"]`,
  `seccompProfile: RuntimeDefault`, and optional `runAsUser`/`runAsGroup`/`fsGroup`.
- Apply it to:
  - `HelmInstallJob` (run non-root, `readOnlyRootFilesystem`, with an `emptyDir` for `HELM_CACHE_HOME`,
    `HELM_CONFIG_HOME` and `HELM_DATA_HOME`; the registry-login step writes config).
  - All `Job/*JobChart` classes and `Nextcloud/Commands/RancherJob.php` (keep Nextcloud uid/fsGroup 33).
  - Sidecars.
  - Resolve WordPress `fsGroup 0` in the spike.
- Security scans: `polaris`, `trivy`, `kubescape` and `nuclei` get the hardened context.
  `kube-bench` and `kube-hunter` cannot satisfy PSA, so run them in a dedicated `kumulicp-scans` namespace
  labelled `privileged`, or restrict them to cluster-level runs. This needs a decision (see §5).
- Note: `drop: ["NET_RAW"]` alone only clears baseline-style concerns. `restricted` requires `drop: ["ALL"]`
  (only `NET_BIND_SERVICE` may be added back). Keep a NET_RAW-only profile as an interim "hardened-minimum" for
  apps that can't yet pass `restricted`.

Tests: a `RestrictedPodSpecAssertion` helper in `tests/Support` that encodes the PSA `restricted` checklist
(containers, initContainers, volumes, host namespaces) and runs against every manifest builder, so regressions
fail CI without a cluster.

Exit: all KumuliCP-created pod specs pass the assertion, and the install Job runs in a `restricted`-enforced
test namespace.

### Phase 3 — App `security` compatibility and configurations

1. **Flag**: add `security` to `AppProfile::compatibilityCatalog()` plus en/es lang strings (and the JS locale
   files if used). The chip shows automatically in `AppView.vue`. Meaning: the app's chart exposes security
   controls KumuliCP manages, and `max_pod_security` is verified.
2. **Profile declaration**: a `protected $security` array on `AppProfile`:
   ```
   max_pod_security: restricted
   settings: map of chart value path → { kind: pod|container, label, recommended }
   ```
   Nextcloud (and its `redis`, `cronjob`, `imaginary` subcharts), WordPress and CiviCRM each declare their
   paths, verified in Phase 0. `GenericAppProfile` does not set the flag; admins can still add
   `additionalConfigs`.
3. **Security configurations**: expose the declared settings as `$configurations` entries (type `yaml`/`bool`)
   under a `security` group. Add the grouping to the plan and config editors (locate the current config
   renderer first). Default is "inherit from namespace policy".
4. **Chart values**: a shared `HelmChart::securityValues()` merges, in order: namespace-policy floor → profile
   recommended → plan override. The override is validated: it cannot weaken below the floor when `enforce` is
   on. Each chart class applies the result at its declared paths.
5. **Discovery**: `ChartSecurityInspector` runs `helm show values` (via `HelmCli`) when an AppVersion's
   chart name or version is saved. It scans for keys like `securityContext`, `podSecurityContext`,
   `containerSecurityContext`, `networkPolicy`, `serviceAccount.automount*` and `readOnlyRootFilesystem`, and
   stores the paths and chart defaults in the AppVersion settings. The Version admin page lists each as
   **Managed / Available / Unknown**. "Manage" creates a plan `additionalConfig` through the existing
   mechanism. Optionally read image metadata (`USER`, exposed ports below 1024) to flag root images.
6. **Highlighting**: a posture badge per app version and plan: *Restricted-ready*, *Baseline-ready*, *Unknown*,
   or *Not compatible*. Show recommendations inline ("This chart supports `runAsNonRoot`. Enable it").

Tests: profile/catalog unit tests, a values test per chart asserting the security paths and the floor,
Inertia feature tests for the AppView and Versions pages, and a discovery test with a fixture `values.yaml`.

Exit: every first-party app shows its posture, its security settings are editable per plan, and the matrix from
Phase 0 shows `restricted` passing for those marked ready.

### Phase 4 — Raise enforcement

1. Soak on warn/audit: zero warnings across a release cycle on a staging server.
2. Preflight clean, then `enforce=baseline` (near-free for these apps, blocks privileged/hostPath/hostNetwork).
3. For namespaces whose active apps are all *Restricted-ready*, `enforce=restricted` with a pinned version.
4. Implement `on_incompatible_app` handling on app activation, and relabel on activate/deactivate.

Rollback: set `enforce` back to null. It is a label, so there is no data impact.

### Phase 5 — Namespace guardrails beyond PSA

Applied by a `NamespaceGuardrails` manifest builder after namespace creation, each toggled in server settings.
Add matching rules (`networkpolicies`, `resourcequotas`, `limitranges`) to the deployer ClusterRole.

- **NetworkPolicy**: default-deny ingress, then allow the ingress controller namespace (Traefik), same-namespace
  traffic, and the database server. Egress: DNS + internet; block other tenants' namespaces and the cloud
  metadata address `169.254.169.254`. Requires a CNI that enforces policies, so detect and warn. Check
  cert-manager HTTP-01 solver pods still work (they run in the org namespace).
- **ResourceQuota/LimitRange** derived from the plan's resource limits.
- **ServiceAccount**: `automountServiceAccountToken: false` by default, with a dedicated SA per app.
- **Labels/annotations**: `kumulicp.io/managed`, org id, for policy engines to match on.

### Phase 6 — Backlog

- Coexistence with external policy engines (Kyverno, Gatekeeper, ValidatingAdmissionPolicy): detect their CRDs
  and suggest `observe` mode.
- Image policy: digest pinning, registry allowlist, signature verification (admin-side), Trivy gating.
- `readOnlyRootFilesystem` with `emptyDir` tmp mounts (not part of `restricted`, app-specific).
- RuntimeClass (gVisor/Kata) per plan, user namespaces.
- Scheduled Polaris/Kubescape scans as a regression detector, plus a per-server security dashboard.

---

## 4. Testing and verification

- **CI, no cluster**: the `restricted` assertion helper over every manifest builder, policy resolution tests,
  chart values tests, and admin UI feature tests.
- **CI, optional e2e**: `kind` ≥ 1.25 job that installs the pinned charts into a `restricted`-enforced
  namespace through the helm driver (extend `docs/testing-helm-driver.md` and the `AccountTest` flow with a
  security conformance step).
- **Runtime**: Polaris/Kubescape scans per namespace after each release.

---

## 5. Decisions needed

1. **Defaults.** Confirm warn + audit `restricted`, enforce off, and `baseline` enforce as the first step in
   Phase 4.
2. **Where policy lives.** Server-wide default plus a per-organization override (proposed), or a "security tier"
   on the base plan?
3. **Scan Jobs.** Dedicated privileged `kumulicp-scans` namespace for `kube-bench`/`kube-hunter`, or cluster-only
   runs?
4. **Incompatible apps.** `block` (proposed default) vs `downgrade` the namespace.
5. **Rancher parity.** Is the Rancher driver still a target, or is `helm_k8s` the only driver we invest in?
   Rancher needs PSACT coordination.
6. **Nextcloud as non-root.** Depends on the Phase 0 spike. If it can't run `restricted`, Nextcloud namespaces
   stay at `baseline` and the posture badge says so.

## 6. Risks

- Relabeling needs an RBAC change on every existing cluster. Without it, Phase 1 silently does nothing, hence
  the `ValidateServer` check.
- `restricted` can break chart upgrades that add init containers or volumes. The Phase 2 CI assertion and the
  discovery step mitigate this.
- Policy applied to all of a namespace at once means one weak app caps the whole org. The effective-level clamp
  makes that explicit.
- Chart `securityContext` key names differ between charts and versions. Never hard-code from memory; verify in
  Phase 0 and by discovery.
