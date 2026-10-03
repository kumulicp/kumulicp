# Kubernetes security hardening plan

Status: decisions resolved. Phase 0 (see `docs/k8s-security-phase0.md`) and Phase 1 (namespace labels, opt-in) are
done. Everything defaults to off, so with no settings changed the app behaves exactly as before.

Goal: let admins run every organization namespace KumuliCP creates, and every app activated in it, under
Kubernetes Pod Security Admission (PSA) at the level each app can handle. Observe first (`warn`/`audit`), fix the
workloads, then enforce. Surface the security settings that app Helm charts and images already offer, encourage
their use, and let admins choose exactly which Linux capabilities are dropped. A sysadmin who already enforces
policy with another tool must be able to opt out of ours.

## Resolved decisions

| # | Decision | Effect on the plan |
|---|---|---|
| 1 | Nothing security-related is enabled by default. | Every control is opt-in. With defaults untouched, namespaces, chart values and Jobs are byte-for-byte what they are today. This includes hardening KumuliCP's own Jobs (§2.1). |
| 2 | Security **tier on base plans**. | Policy is chosen per base `Plan`, not per server or organization (§2.3). |
| 3 | `kube-bench` may have privileges; `kube-hunter` may not. | `kube-bench` runs in a dedicated privileged namespace; `kube-hunter` runs hardened in the org namespace (§Phase 2). |
| 4 | `on_incompatible_app` is a **system setting**. | Lives in the global settings store, not on tiers (§2.4). |
| 5 | Rancher parity is required. | Every phase specifies both drivers; differences are listed in §2.7. |
| 6 | Nextcloud stays at `baseline`. | Nextcloud's profile declares `max_pod_security: baseline`. Its namespaces are clamped to `baseline`, and it still gets in-baseline hardening (§Phase 3). |
| 7 | Admins can pick which capabilities are dropped, including when `drop: ALL` isn't possible. | New capability catalog and selector (§2.6). |

---

## 1. What exists today (findings)

| Area | Finding | Where |
|---|---|---|
| Namespace creation | The manifest has only `name`. No labels, so no PSA. `update()` is an empty no-op, so existing namespaces never get reconciled. | `app/Integrations/ServerManagers/HelmKubernetes/API/KubernetesNamespace.php` |
| Rancher driver | Creates namespaces over the REST API with only the project id label/annotation. Rancher's own Pod Security Admission Configuration Templates (PSACT) apply per project and may conflict with namespace labels. | `.../Rancher/API/KubernetesNamespace.php` |
| Deployer RBAC | `kumulicp-deployer` can only `get/list/create/delete` namespaces. It cannot `patch`/`update`, so relabeling existing namespaces fails until the ClusterRole is extended. | `docs/k8s-rbac-sample.yaml` |
| Chart values | None of `NextcloudChart`, `WordpressChart` or `CiviCRMStandaloneChart` set any `securityContext`/`podSecurityContext`/`containerSecurityContext`. What runs is exactly the chart default. Phase 0 rendered those defaults (see the report). | `.../Rancher/Charts/*`, `Applications/CiviCRMStandalone/CiviCRMStandaloneChart.php` |
| Helm install Job | `HelmInstallJob::jobManifest()` runs `alpine/helm` with no `securityContext`: root, writable root FS. Only applies to the `helm_k8s` driver. Rancher runs its own install pods. | `.../HelmKubernetes/Support/HelmInstallJob.php` |
| Other KumuliCP Jobs | `NextcloudJobChart` sets `fsGroup 33` and `runAsUser 33` at pod level, plus `allowPrivilegeEscalation: true` in the **pod** securityContext, which is a container-level field. It is ignored: the API server only warns about unknown fields by default, and the `helm_k8s` driver posts raw JSON through its REST client. `WordpressJobChart` sets `fsGroup 0`. `MySQLJobChart` and `Nextcloud/Commands/RancherJob.php` set little or nothing. | `.../Rancher/Charts/Job/*` |
| Security scans | `RunSecurityScan` runs the scan Job in the org namespace with no host access. `kube-bench` can't audit nodes without host mounts and `hostPID`, so today it is largely ineffective. `kube-hunter` runs in `--pod` mode and needs no host access. | `app/Actions/Security/RunSecurityScan.php`, `SecurityScanJobChart.php`, `Support/Security/Tools/*` |
| Compatibility flags | `AppProfile::$compatibility` is a list of flags. `compatibilityCatalog()` lists the known flags, and `Admin\Applications::show()` renders them as chips in `AppView.vue`. Adding a flag needs a catalog entry plus `applications.compatibility.<flag>.{label,description}` strings in `resources/lang/{en,es}/admin.php`. | `app/Integrations/Applications/AppProfile.php` |
| App configurations | `AppProfile::$configurations` (name/type/default/validations) are mapped into chart values via `$app_instance->configuration('x')`. Plans can also define `additionalConfigs`. `HelmChart::valuesWithAdditionalConfigs()` applies them with the `a-b` → `a.b` key convention. This is the hook for exposing chart settings. | `HelmChart.php`, `AppPlan::additionalConfigs()` |
| Settings storage | System settings: `Settings` facade over `ServerSetting` (key/value). Per-server: `Server::settings` JSON. Base plans: `Plan::settings` JSON. A policy needs no migration. | `app/Services/SettingsService.php`, `app/Server.php`, `app/Plan.php` |
| Existing tooling | Polaris, Kubescape, Trivy, kube-bench and kube-hunter scans, plus `SecurityFinding` storage, already exist. They are a ready verification and regression loop. | `app/Support/Security/Tools/*` |

Consequence: a namespace holds multiple apps plus KumuliCP's own Jobs. The effective PSA level of a namespace is
capped by its least-hardened workload, so policy is computed per namespace.

---

## 2. Design

### 2.1 Three layers, all opt-in

1. **Namespace policy** (admin-owned). PSA levels and guardrails applied to each org namespace, selected by the
   organization's base-plan tier.
2. **App security settings** (chart-owned). Whatever the chart exposes (`securityContext`, capabilities,
   `networkPolicy`, service account token, and so on) is shown as app configuration, with KumuliCP recommending
   values. Nothing is applied until an admin sets it.
3. **KumuliCP's own workloads** (install Jobs, helper Jobs, scan Jobs). Hardened only when the system setting
   `security.harden_system_jobs` is on (default off), because changing how the installer runs is itself a
   behavior change. Phase 2 makes the hardened form available and tested.

### 2.2 Ownership mode per Server

`security.mode` in server settings, default **`off`**:

- `off`: nothing is written to the cluster.
- `managed`: KumuliCP applies and reconciles namespace labels and guardrails for the tiers in use.
- `observe`: KumuliCP never writes policy. It still runs preflight checks and shows findings, for admins who use
  Kyverno, Gatekeeper, ValidatingAdmissionPolicy or Rancher PSACT.

Chart-level settings (layer 2) are independent of this mode; they are plain chart values.

### 2.3 Security tier on base plans

`Plan::settings['security']['tier']` selects a tier, default `none`. Tiers are defined in system settings
(`security.tiers`), with these presets shipped but unused until a plan selects one:

| Tier | enforce | warn | audit | Default capability drop (see §2.6) |
|---|---|---|---|---|
| `none` (default) | none | none | none | none |
| `observe` | none | restricted | restricted | none |
| `baseline` | baseline | restricted | restricted | `NET_RAW` |
| `restricted` | restricted | restricted | restricted | `ALL` |

A tier is a small value object (`App\Support\Security\SecurityTier`):

```
pod_security: { enforce|warn|audit: null|privileged|baseline|restricted, *_version: latest|vX.Y }
capabilities: { drop: [...], add: [...] }   # defaults for apps/Jobs under this tier
network_policy: off | default_deny_with_allows
resource_limits: off | from_plan
automount_sa_token: unchanged | false
```

Admins can edit tier definitions or add their own in system settings. Resolution for a namespace:
`organization → plan → tier`, then clamped by app capability (§2.4). There is no per-organization override. If
an organization needs an exception, give it its own base plan.

### 2.4 Effective level per namespace

```
effective_enforce = min(tier.enforce, strictest level every active app + KumuliCP Jobs pass)
```

Each app profile declares `max_pod_security`, the highest level it is verified for (Nextcloud: `baseline`).

`security.on_incompatible_app` is a **system setting** (`block` | `downgrade` | `allow`, default `block`). It
only matters once a tier enforces, so it changes nothing by default:

- `block`: activating an app whose `max_pod_security` is below the tier's `enforce` fails with a clear error.
- `downgrade`: relabel the namespace to the highest level all apps support and notify the admin.
- `allow`: activate anyway; the namespace's enforce level is clamped, and a finding is recorded.

Ordering during rollout: harden workloads → verify no `warn` output → only then raise `enforce`.

### 2.5 Getting feedback (the part that makes warn/audit useful)

- `warn` is only visible to the client making the request. Here that is the `helm` process inside the install
  Job, so its warnings land in the Job logs. After install/upgrade, read them with `Pod::logsForJob()`, parse
  `Warning: would violate PodSecurity "restricted:…"`, and attach them to the app instance and Task. For
  Rancher, the chart install runs in Rancher's own operation pods, so the equivalent is the Rancher app/operation
  status plus the preflight below.
- `audit` goes to the API server audit log, which most admins can't see. Treat it as an admin-side extra.
- **Preflight**: a server-side dry-run of the label change (a `PATCH` of the namespace with `?dryRun=All`
  setting `pod-security.kubernetes.io/enforce=<level>`) returns warnings, in the response's `Warning` headers,
  listing every existing pod that would violate. The `KubernetesApiClient` doesn't expose response headers or a
  dry-run option yet, so Phase 1.5 adds both. Wrap it as `PodSecurityPreflight` and store the result as a
  `SecurityScan` (tool `pod-security`) with `SecurityFinding` rows, reusing the existing UI. For Rancher, use the
  same call through Rancher's Kubernetes proxy, or fall back to the static checker on rendered manifests.
- **Static check**: `scripts/security/psa-check.py` (Phase 0) evaluates rendered manifests offline. Phase 2 ports
  its rule table to a PHP `RestrictedPodSpecAssertion` for CI.

### 2.6 Capability selection

Requirement: let admins choose which capabilities are dropped, and still allow trimming when the image needs some
(so `drop: ALL` isn't an option).

**Catalog** (`App\Support\Security\LinuxCapabilities`): every Linux capability with a label, description,
risk note, and three flags:

- `runtime_default`: in the default containerd/CRI-O/Docker set (`AUDIT_WRITE, CHOWN, DAC_OVERRIDE, FOWNER,
  FSETID, KILL, MKNOD, NET_BIND_SERVICE, NET_RAW, SETFCAP, SETGID, SETPCAP, SETUID, SYS_CHROOT`). Only these are
  present to drop, so this is the list the selector pre-populates.
- `psa_baseline_addable`: may be added back at `baseline` (the same set minus `NET_RAW`).
- `psa_restricted_addable`: only `NET_BIND_SERVICE`.

**Model**: `capabilities: { drop: ["ALL"] | [list], add: [list] }` per container group.

**App profile metadata**: `capabilities: { required: [...], droppable: [...] }` per container (main, cron,
sidecars). `required` comes from the Phase 0 probe (`scripts/security/capability-probe.sh`) or the image
vendor's documentation. `droppable` is what the probe showed is unnecessary.

**Selector UI** (plan editor "Security" section; tier editor for defaults):

- A checklist of the runtime-default capabilities, each with its description and risk.
- Capabilities in `required` are shown locked as "needed by this image".
- Capabilities in `droppable` are pre-checked as the recommendation.
- A **Drop ALL** option. If the profile has `required` capabilities, it becomes **Drop ALL and add back
  required**, which is valid Kubernetes and is rendered as `drop: [ALL], add: [required]`. If the admin prefers,
  they can instead drop only specific capabilities and leave the rest, which is the "can't drop all" case: the
  unneeded ones are still removed.
- A live "Pod Security level this allows" indicator (below).

**Validation and PSA ceiling**: a drop list can't include a `required` capability. The `add` list determines the
highest PSA level the app can satisfy: empty or `NET_BIND_SERVICE` only → `restricted` capable (together with
`drop: ALL`); a subset of the baseline-addable set → `baseline`; anything else → `privileged`. That ceiling
feeds §2.4, so adding back `CHOWN` for Nextcloud keeps it at `baseline` and the selector says so.

**Where it applies**: chart value paths declared in the profile (§Phase 3), a tier's default for KumuliCP's own
Jobs, and per-plan overrides stored as app plan configurations (`security-capabilities-drop`,
`security-capabilities-add`, type `array`).

### 2.7 Rancher parity

| Concern | `helm_k8s` driver | Rancher driver |
|---|---|---|
| Namespace labels | `KubernetesApiClient::apply()` (create, or JSON merge patch) | `PUT/PATCH /v1/namespaces/{name}` with `metadata.labels` (confirm verb in Phase 1) |
| Reconcile permission | `get` + `patch` on `namespaces` for `kumulicp-deployer` | The Rancher API user must be allowed to update namespaces |
| Project-level policy | n/a | Rancher PSACT can set defaults and exemptions per project; `observe` mode is the right choice when it is in use |
| Install Job hardening | `HelmInstallJob` | Not applicable: Rancher's own operation pods run the install |
| Helper/scan Jobs | `Job/*JobChart` via `KubernetesApiClient::apply()` | The same `Job/*JobChart` manifests via the Rancher Job API, so one hardening change covers both |
| Install-time warnings | Job logs | Rancher operation status + preflight |
| Chart values | Shared `HelmChart` classes | Shared `HelmChart` classes, so Phase 3 covers both with no duplication |

---

## 3. Phased implementation

### Phase 0 — Baseline and tooling ✅ done

Report: `docs/k8s-security-phase0.md`. Deliverables:

- `scripts/security/psa-check.py`: static baseline/restricted checker for rendered manifests.
- `scripts/security/capability-probe.sh`: finds the minimal capability set an image needs (needs a container
  runtime, so not run in the authoring environment).
- App × violation matrix for Nextcloud 9.0.5 and Bitnami WordPress 27.0.0, verified chart value paths, and an
  audit of KumuliCP-owned workloads.
- Still to do on a machine with a cluster/runtime: confirm the matrix with `warn=restricted` on `kind`, run the
  capability probe per image, and obtain the CiviCRM chart (`repo.kumuli.dev` wasn't reachable).

### Phase 1 — Namespace labels (opt-in) ✅ done

What shipped, all inert until an admin turns it on:

- **Tiers**: `App\Support\Security\SecurityTier` (value object, presets `none`/`observe`/`baseline`/`restricted`),
  admin-defined tiers stored as JSON in the system setting `security_tiers`, and `NamespaceSecurityPolicy` which
  resolves `organization → plan → tier` (`Plan::settings['security']['tier']`). An unknown or removed tier falls
  back to `none`.
- **Server mode**: the flat server setting `security_mode` (`off` default, `managed`, `observe`), validated on
  the server edit form and documented in each driver's settings help. Only `managed` writes anything. `observe`
  currently behaves like `off` and is reserved for the preflight reporting in §2.5.
- **Both drivers**: `KubernetesNamespace::create()` adds the labels and a `kumulicp.io/security-tier` ownership
  annotation, and `update()` reconciles an existing namespace (shared `ReconcilesNamespaceSecurity` trait,
  pure `NamespaceSecurityReconciler` for the diff). `helm_k8s` uses `KubernetesApiClient::apply()`, which is a
  JSON merge patch for an object that already exists (a `null` value removes a label), so it needs only `get` and
  `patch` on namespaces. No `kubectl` or `helm` binary is involved. Rancher reads the namespace and PUTs it back with the label changes; **that path hasn't been run
  against a live Rancher**, so confirm it before relying on it. Unmanaged servers make no cluster call at all.
  The ownership annotation means a plan moving back to `none` removes only the labels KumuliCP set, never labels
  an admin put on a namespace it never managed.
- **Reconcile**: `OrganizationServices::updateOrganization()` now calls the namespace `update()`, so the existing
  `UpdateOrganization` job covers it. It also runs when an app is activated, and when an admin changes a plan's
  tier (all subscribers are re-synced). Backfill: `php artisan servers:sync-namespace-security [--dry-run]
  [--organization=slug] [--server=id]`, which prints a table and exits non-zero on failure.
- **RBAC**: `docs/k8s-rbac-sample.yaml` adds `patch` on namespaces (only needed for `managed`). A "forbidden"
  failure is logged and surfaced by the sync command with a pointer to the RBAC sample. (I used that instead of
  a `ValidateServer` check, which is an end-to-end activation test, not a permission probe.)
- **Admin UI**: Settings → "Namespace Security" (custom tiers; a tier a plan still uses can't be removed), a tier
  select on the plan editor, and `security_mode` in the server settings.
- **Install-time warnings** (`helm_k8s` only): after an activate/upgrade completes, if the namespace is managed and
  its tier warns or enforces, the latest install Job's logs are parsed for `would violate PodSecurity` and shown on
  the organization's app page. Rancher has no equivalent yet; its installs run in Rancher's own pods.

Not done yet, deliberately: the `on_incompatible_app` and `harden_system_jobs` system settings. Nothing reads
them until Phase 2/3, and a setting with no effect would mislead admins. They land with the code that uses them.

Tests: unit tests for tiers, the reconciler, the policy and the warning parser; driver tests with `Http::fake`
(`tests/Unit/HelmKubernetes/NamespaceSecurityTest.php`, `tests/Unit/Rancher/NamespaceSecurityTest.php`); feature tests for the settings page, plan tier and
server mode (`tests/Feature/Admin/NamespaceSecurityTest.php`). **These were written but not run**: the project
needs PHP 8.4 and the authoring environment has 8.3. The pure logic (tier, reconciler, warning parser) was
exercised with plain PHP scripts, Pint passes, and the Vue pages compile.

Exit: with a plan on `observe` and a `managed` server, its namespaces carry warn/audit `restricted` and
violations per app are visible. With defaults, nothing changes.

### Phase 1.5 — Preflight over the REST client (next)

The `helm_k8s` driver no longer shells out to `kubectl` or `helm` (branch
`claude/kubectl-helm-removal-k8s-api-g24bd9`, which Phase 1 is now built on), so the dry-run preflight in §2.5
needs two small additions to `KubernetesApiClient`: an optional `dryRun` query parameter on writes, and the
response's `Warning` headers returned alongside `data` (e.g. as `warnings`). On top of that:
`PodSecurityPreflight` (dry-run the target `enforce` label, store the warnings as a `SecurityScan` with findings),
and the gate that blocks raising a tier's `enforce` level while the preflight lists violations, with an admin
override.

### Phase 2 — Make KumuliCP's own workloads hardenable

- A `PodSecurityContexts` helper returning `restricted`-conformant pod and container fragments (`runAsNonRoot`,
  `allowPrivilegeEscalation: false`, capabilities from §2.6, `seccompProfile: RuntimeDefault`).
- Apply it behind `security.harden_system_jobs` (default off) to:
  - `HelmInstallJob`: run non-root, `readOnlyRootFilesystem`, with an `emptyDir` for `HELM_CACHE_HOME`,
    `HELM_CONFIG_HOME` and `HELM_DATA_HOME` (the registry-login step writes config).
  - All `Job/*JobChart` classes and `Nextcloud/Commands/RancherJob.php`. Also fix Nextcloud's misplaced
    `allowPrivilegeEscalation` (move it to the container). Revisit WordPress's `fsGroup 0`.
  - Sidecars.
- **Scans**:
  - `kube-hunter` (`--pod` mode): runs in the org namespace with the hardened context. No privileges.
  - `kube-bench`: runs in a dedicated `kumulicp-scans` namespace labelled `privileged`, with the `hostPID`
    and host mounts it actually needs (today's Job gives it none). Add a per-tool pod-spec hook to
    `SecurityToolProfile`. Document the namespace and a ServiceAccount/RBAC sample in `docs/`.
  - `polaris`, `trivy`, `kubescape`, `nuclei`: hardened context if Phase 0/CI shows they run unprivileged.
- Rancher: the same `Job/*JobChart` changes apply through the Rancher Job API.

Tests: a PHP `RestrictedPodSpecAssertion` in `tests/Support` that encodes the PSA checklist (ported from
`psa-check.py`) and runs against every manifest builder. Hardened output must pass it, and default output must
be unchanged.

Exit: every KumuliCP-created pod spec passes `restricted` when the setting is on; defaults unchanged.

### Phase 3 — App `security` compatibility and configurations

1. **Flag**: add `security` to `AppProfile::compatibilityCatalog()` plus en/es lang strings. The chip shows
   automatically in `AppView.vue`. Meaning: the chart exposes security controls KumuliCP manages, and
   `max_pod_security` is verified.
2. **Profile declaration**: a `protected $security` array on `AppProfile`:
   ```
   max_pod_security: restricted | baseline
   settings: chart value path → { kind: pod|container, label, recommended }
   capabilities: per container { required, droppable }   # §2.6
   ```
   Paths verified in Phase 0:
   - **Nextcloud (`max_pod_security: baseline`)**: `nextcloud.securityContext` (main container only),
     `nextcloud.podSecurityContext`, `nginx.securityContext`, `cronjob.sidecar.securityContext`,
     `cronjob.cronjob.securityContext`, `imaginary.securityContext`/`podSecurityContext`,
     `metrics.securityContext`/`podSecurityContext`, init containers
     `nextcloud.{mariaDb,postgreSql}InitContainer.securityContext`, and the Redis subchart's own values.
     Imaginary and metrics already run non-root, so they can be raised to restricted-level settings, but the
     namespace is still clamped by the main container.
   - **WordPress (Bitnami)**: `podSecurityContext`, `containerSecurityContext`, `metrics.containerSecurityContext`,
     `automountServiceAccountToken`, `serviceAccount.automountServiceAccountToken`, `networkPolicy.*`. Defaults
     already pass `restricted`.
   - **CiviCRM**: pending the chart.
   `GenericAppProfile` doesn't set the flag. Admins can still add `additionalConfigs`.
3. **Security configurations**: expose declared settings as `$configurations` entries (type `yaml`/`bool`/`array`)
   in a `security` group, with the capability selector (§2.6). Add the grouping to the plan and config editors
   (locate the current renderer first). Default is **unset**, so the chart default applies.
4. **Chart values**: a shared `HelmChart::securityValues()` merges, in order: tier defaults → profile
   recommended (only when the admin accepts the recommendation) → plan override. Because the `HelmChart` classes
   are shared, this covers Rancher and `helm_k8s` at once. Overrides are validated against the tier's `enforce`
   level and the PSA ceiling.
5. **Discovery**: `ChartSecurityInspector` reads the chart's `values.yaml` when an AppVersion's chart name or
   version is saved. There's no `helm` binary any more (the REST-client change removed `HelmCli`), so a
   `ChartValuesReader` fetches it over HTTP: for a classic repository, `index.yaml` then the chart `.tgz`; for an
   `oci://` repository, the registry's token, manifest and chart layer blob. It reuses the AppVersion's repo
   secret for private repositories and extracts `values.yaml` in PHP. It scans for keys like `securityContext`, `podSecurityContext`,
   `containerSecurityContext`, `networkPolicy`, `serviceAccount.automount*` and `readOnlyRootFilesystem`, and
   stores the paths and chart defaults on the AppVersion. The Version page lists each as
   **Managed / Available / Unknown**. "Manage" creates a plan `additionalConfig` through the existing mechanism.
   It only needs repository access, so it works the same for Rancher servers.
6. **Highlighting**: a posture badge per app version and plan: *Restricted-ready*, *Baseline-ready*, *Unknown*,
   or *Not compatible*. Show recommendations inline ("This chart supports `runAsNonRoot`. Enable it").

Tests: profile/catalog unit tests, a values test per chart asserting the security paths, the PSA ceiling and
capability validation, Inertia feature tests for the AppView, Versions and plan pages, and a discovery test with
a fixture `values.yaml`.

Exit: every first-party app shows its posture, its settings are editable per plan, and the Phase 0 matrix holds
for the combinations marked ready.

### Phase 4 — Raise enforcement (admin-driven)

1. Soak on `observe` tiers: zero warnings across a release cycle on staging.
2. Preflight clean, then move plans to the `baseline` tier.
3. For plans whose active apps are all *Restricted-ready*, the `restricted` tier with a pinned version.
4. `on_incompatible_app` handling on app activation; relabel on activate/deactivate.

Rollback: set the plan's tier back. It is a label, so there is no data impact.

### Phase 5 — Namespace guardrails beyond PSA

Applied by a `NamespaceGuardrails` builder after namespace creation, each toggled in the tier. Add matching RBAC
(`networkpolicies`, `resourcequotas`, `limitranges`) to the deployer ClusterRole and the Rancher equivalent.

- **NetworkPolicy**: default-deny ingress, then allow the ingress controller namespace (Traefik), same-namespace
  traffic, and the database server. Egress: DNS + internet; block other tenants and `169.254.169.254`. Requires
  a CNI that enforces policies, so detect and warn. Note the Bitnami WordPress chart already enables its own
  NetworkPolicy by default, so these must be coordinated. Check cert-manager HTTP-01 solver pods.
- **ResourceQuota/LimitRange** derived from the plan's resource limits.
- **ServiceAccount**: `automountServiceAccountToken: false` where the tier asks for it.
- **Labels/annotations**: `kumulicp.io/managed`, org id, for policy engines to match on.

### Phase 6 — Backlog

- Detect Kyverno/Gatekeeper/ValidatingAdmissionPolicy CRDs and suggest `observe`.
- Image policy: digest pinning, registry allowlist, signature verification, Trivy gating. Bitnami moved older
  images to `docker.io/bitnamilegacy`, so pinned tags can disappear.
- `readOnlyRootFilesystem` with `emptyDir` tmp mounts (not part of `restricted`, app-specific).
- RuntimeClass (gVisor/Kata) per plan, user namespaces.
- Scheduled Polaris/Kubescape scans as a regression detector, plus a per-server security dashboard.

---

## 4. Testing and verification

- **CI, no cluster**: `psa-check.py` and the PHP assertion over rendered charts/manifests, tier resolution tests,
  chart values tests, and admin UI feature tests.
- **CI, optional e2e**: `kind` ≥ 1.25 job that installs the pinned charts into a labelled namespace through the
  helm driver (extend `docs/testing-helm-driver.md` and the `AccountTest` flow with a security conformance step).
- **Runtime**: Polaris/Kubescape scans per namespace after each release.

## 5. Open questions

1. **`on_incompatible_app` default.** I used `block`, which is inert until a tier enforces. Confirm, or default to
   `allow`.
2. **Tier presets.** Are the four presets in §2.3 right, or should custom tiers only be admin-created?
3. **CiviCRM chart.** `repo.kumuli.dev` wasn't reachable here. Can you provide the chart (or a `helm template`
   render) so Phase 0 can cover it?
4. **Cluster for verification.** The matrix is static. Someone needs to run the `kind` check and the capability
   probe on a machine with a cluster or Docker.

## 6. Risks

- Relabeling needs an RBAC change on every existing cluster (and a Rancher permission). Without it, Phase 1 does
  nothing, hence the `ValidateServer` checks.
- `restricted` can break chart upgrades that add init containers or volumes. The CI assertion and the discovery
  step mitigate this.
- Policy applies to a whole namespace, so one weak app caps the org. The clamp makes that explicit.
- Chart `securityContext` key names differ between charts and versions. Verify per version (Phase 0 did this for
  the pinned Nextcloud chart) and rely on discovery.
- A static check approximates admission. It can't see mutating webhooks or runtime behavior, so confirm on a
  cluster before enforcing.
