# Kubernetes network policy plan

Status: plan only, nothing implemented. Builds on the security hardening work in `docs/k8s-security-plan.md`
(tiers on base plans, `security_mode`, the namespace reconciler, preflight-as-a-`SecurityScan`) and replaces the
NetworkPolicy bullet of that plan's Phase 5. Everything defaults to off, so with no settings changed the app
behaves exactly as before.

Goal: isolate each organization's namespace from every other tenant at the network level, with a **base layer**
that works on any cluster whose CNI enforces Kubernetes `NetworkPolicy`, and a **pluggable layer 7 provider**
(Istio first) for admins who run a mesh and want identity-based, HTTP-aware policy and mTLS on top. The base layer
always stays in place under an L7 provider, as defense in depth.

### What "layer 4/5" and "layer 7" mean here

- **Base layer (L3/L4, "4/5" in the request)**: Kubernetes `networking.k8s.io/v1` `NetworkPolicy`. It matches on
  namespaces, pod labels, IP blocks, and TCP/UDP/SCTP ports, and decides whether a connection may be opened. That
  is as high as plain Kubernetes goes: there is no native session-layer control. The closest thing to "L5"
  (who is on the other end of an established session) is workload identity with mTLS, which only an L7 provider
  can give. So this plan puts ports and protocols in the base layer and session identity in the provider layer.
  Open question 8 asks you to confirm that reading.
- **Provider layer (L7)**: the provider's own CRDs (for Istio: `AuthorizationPolicy`, `PeerAuthentication`,
  `Sidecar`, `ServiceEntry`). Rules can name service-account identities, HTTP methods, paths and hosts, and
  require mTLS.

## Resolved decisions

These carry over from the hardening plan, or are defaults picked for this plan. Open questions are at the end.

| # | Decision | Effect on the plan |
|---|---|---|
| 1 | Nothing is enabled by default. | No NetworkPolicy or mesh object is written until an admin sets a server's `network_policy_mode` to `managed` **and** a plan's tier asks for it. Chart values are untouched by default too. |
| 2 | Policy is chosen per **base plan**, through its security tier. | The tier gets a `network` block (§2.2). No per-organization override; an exception gets its own base plan. |
| 3 | Rancher parity is required. | Every phase specifies both drivers; differences are in §2.9. |
| 4 | Nextcloud stays at PSA `baseline`. | An L7 provider that would inject privileged init containers can't push Nextcloud's namespace above or below what the PSA plan allows; see §2.7. |
| 5 | Base layer is provider-neutral and always present. (default picked) | L7 providers add to it; they never replace it. Removing a provider leaves the base layer working. |
| 6 | Ingress isolation ships before egress restriction. (default picked) | Ingress is low-risk and gives tenant isolation. Egress breaks things more easily, so it is a separate tier switch and a later phase. |
| 7 | Istio is the first L7 provider, ambient mode preferred. (default picked) | Ambient keeps PSA levels intact (no `istio-init` with `NET_ADMIN`). Sidecar mode is supported only with the Istio CNI node agent. |

---

## 1. What exists today (findings)

| Area | Finding | Where |
|---|---|---|
| Namespaces | One namespace per organization, named after its slug. A namespace holds all of the org's apps and KumuliCP's helper Jobs. The "shared" organization (`Organization::type = shared`) has its own namespace too. | `HelmKubernetes/Kubernetes.php::namespace()`, `Rancher/API/KubernetesNamespace.php`, `Admin/SharedApps.php` |
| NetworkPolicy | KumuliCP creates none. The `helm_k8s` client's kind table doesn't list `NetworkPolicy`, and the deployer ClusterRole has no `networkpolicies` rules. Charts can create them through the per-release installer identity. | `HelmKubernetes/Support/KubernetesApiClient.php` (`RESOURCES`), `docs/k8s-rbac-sample.yaml` |
| Chart-owned policies | Bitnami WordPress enables its own NetworkPolicy by default with `allowExternal: true` and `allowExternalEgress: true`. NetworkPolicies are **additive**, so that chart policy re-opens WordPress pods to every namespace even under our default-deny. Nextcloud's chart has no `networkPolicy` value; its Bitnami Redis subchart likely has one and must be checked (Phase N0). | `docs/k8s-security-phase0.md` §2 and finding 3 |
| Ingress | Traefik is the expected controller (`k8s_ingress_class`, Middleware CRDs, `router.middlewares` annotations). Its namespace isn't recorded anywhere, so a policy can't select it yet. cert-manager HTTP-01 solver pods run in the org namespace and are reached through the controller. | `HelmKubernetes/API/Middleware.php`, `Rancher/Charts/*Chart.php` |
| Platform endpoints | Apps reach the database via the database Server's `internal_address` (an IP, a hostname, or a Service DNS name; no port is stored, so MySQL's 3306 is implied). LDAP comes from global config (`ldap.connections.default.hosts.0`/`port`). SMTP is a per-app configuration (`nextcloud-mail-smtp-host`/`port`). Authentik is reached at its public URL. The mail server can run in-cluster (DockerMailServer chart). | `Rancher/Charts/NextcloudChart.php`, `WordpressChart.php`, `CiviCRMStandaloneChart.php`, `Nextcloud/NextcloudEnvVars.php` |
| KumuliCP Jobs | `HelmInstallJob` pods (label `app.kubernetes.io/managed-by: kumulicp-helm-installer`) talk to the API server and to chart repositories and registries. Scan Jobs talk to the API server, vulnerability databases (Trivy) and public app URLs (Nuclei). `kube-hunter --pod` deliberately probes the cluster network. | `HelmKubernetes/Support/HelmInstallJob.php`, `app/Support/Security/Tools/*` |
| Reusable pieces | `SecurityTier`, `NamespaceSecurityPolicy` (org → plan → tier), the ownership-annotation reconciler pattern, `KubernetesApiClient::apply()`/`delete()`, `PodSecurityPreflight` recording into `SecurityScan`/`SecurityFinding`, and the `SecurityToolService` register-by-name pattern. | `app/Support/Security/*`, `app/Services/SecurityToolService.php` |
| Rancher | All KumuliCP organizations live in **one** Rancher project (`project_id` setting). If Rancher's Project Network Isolation is on, Rancher's own policies allow traffic between all namespaces of a project, which (being additive) lets tenants reach each other no matter what we apply. | `Rancher/API/KubernetesNamespace.php`, `admin.servers.rancher.settings` |

Consequence: tenant isolation is a property of the **union** of every NetworkPolicy selecting a pod. KumuliCP must
detect and neutralize allow-all policies it doesn't own (chart defaults, Rancher project isolation), not just add
its own.

---

## 2. Design

### 2.1 Layers and ownership

1. **Intent** (provider-neutral). A `NetworkIntent` per namespace: a list of allowed flows built from the tier, the
   server's cluster facts, the active apps' declared needs, and the platform endpoints. Pure value objects in
   `App\Support\Security\Network`, no framework dependencies, so they are unit-testable.
2. **Base renderer**. `KubernetesNetworkPolicyProvider` turns the intent into `NetworkPolicy` objects. Always used
   when the tier asks for network policy.
3. **L7 renderers** (optional, pluggable). A provider registered with `NetworkPolicyProviderService` (§2.6) turns
   the same intent, plus any L7 detail, into its own CRDs, and may ask the base renderer for extra allows it needs
   (for example Istio's HBONE port).

Every object KumuliCP writes carries `app.kubernetes.io/managed-by: kumulicp` and a `kumulicp.io/network-tier`
annotation, and is named `kumulicp-*`. The reconciler only updates or deletes objects carrying that label, so it
never touches an admin's or a chart's own policies, and a plan going back to `none` removes exactly what we made.

### 2.2 Tier fields

`SecurityTier` gains a `network` block, all values defaulting to off:

```
network:
  ingress: off | namespace | app        # app = per-app microsegmentation (Phase N5)
  egress: off | restrict
  egress_internet: allow | deny          # only read when egress = restrict
  l7_provider: null | istio | <registered key>
  l7_mtls: off | permissive | strict     # only read when l7_provider is set
  chart_policies: leave | neutralize     # what to do with chart-shipped allow-all policies (§2.5)
```

Presets (unused until a plan selects one): `none` (all off), `observe`, `baseline` and `restricted` keep
`network` off, plus two new presets: `isolated` (ingress `namespace`, egress off) and `isolated-egress` (ingress
`namespace`, egress `restrict`, internet `allow`). Admins can set these fields on custom tiers. Resolution stays
`organization → plan → tier`.

### 2.3 Server settings (cluster facts)

A plan says what it wants; the server says what its cluster can do. Flat server settings, like `security_mode`:

| Setting | Default | Purpose |
|---|---|---|
| `network_policy_mode` | `off` | `off`: write nothing. `managed`: reconcile policies for the tiers in use. `observe`: never write; still build the intent, show it, and run verification probes. Separate from `security_mode` because CNI and mesh are cluster facts an admin may want on independently of PSA. |
| `network_ingress_namespaces` | none | Namespace(s) of the ingress controller (e.g. `["traefik"]` or `["kube-system"]`). Required before `managed` does anything for ingress; no guessing. |
| `network_ingress_cidrs` | none | For controllers on `hostNetwork`, whose traffic arrives from node IPs that a namespace selector can't match. |
| `network_cluster_cidrs` | none | Pod and Service CIDRs, excluded from "internet" egress so it can't be used to reach other tenants. Required for `egress: restrict`. |
| `network_api_server_cidrs` | read from `default/kubernetes` EndpointSlice | API server addresses for KumuliCP Jobs' egress. A setting overrides discovery. |
| `network_monitoring_namespaces` | none | Optional allow for a Prometheus namespace to scrape metrics ports. |
| `network_l7_providers` | none | Which registered providers are installed on this cluster, with provider options (e.g. `{"istio": {"mode": "ambient", "gateway": "traefik"}}`). |

### 2.4 Base layer policy set (per org namespace)

Ingress (`ingress: namespace`):

1. `kumulicp-default-deny-ingress`: `podSelector: {}`, `policyTypes: [Ingress]`.
2. `kumulicp-allow-same-namespace`: from `podSelector: {}`. An org's apps can talk to each other and their own
   databases and Redis.
3. `kumulicp-allow-ingress-controller`: from `network_ingress_namespaces` (by `kubernetes.io/metadata.name`) or
   `network_ingress_cidrs`. All ports, which also covers cert-manager HTTP-01 solver pods.
4. `kumulicp-allow-monitoring`: from `network_monitoring_namespaces` to the metrics ports apps declare (§2.5).

Egress (`egress: restrict`):

5. `kumulicp-default-deny-egress`: `podSelector: {}`, `policyTypes: [Egress]`.
6. `kumulicp-allow-dns`: to `kube-system` pods labelled `k8s-app: kube-dns`, UDP and TCP 53.
7. `kumulicp-allow-same-namespace-egress`.
8. `kumulicp-allow-ingress-hairpin`: to the ingress controller namespace on 80/443. When a pod calls a public
   URL served by the same cluster (Authentik, another org's app, a shared app), kube-proxy short-circuits it to
   the controller's pods, so this is what keeps those calls working once egress is restricted.
9. `kumulicp-allow-platform`: one rule per platform endpoint the namespace's apps use, built by
   `PlatformEndpointResolver`:
   - IP or CIDR → `ipBlock` with its port.
   - Service DNS (`*.svc`, `*.svc.cluster.local`) → `namespaceSelector` + port.
   - Other hostname → resolved at reconcile time into `/32` blocks, with a `SecurityFinding` saying the policy
     goes stale if the IP changes, and a pointer to set an explicit CIDR on that Server.
   - Sources: the org's database Server (3306 unless the Server declares a port), LDAP from config, SMTP host/port
     from each app's configuration, the in-cluster mail server when one is set.
10. `kumulicp-allow-internet` (when `egress_internet: allow`): `0.0.0.0/0` and `::/0` except RFC 1918, CGNAT
    `100.64.0.0/10`, link-local `169.254.0.0/16` (cloud metadata), `fc00::/7`, `fe80::/10`, and
    `network_cluster_cidrs`.
11. `kumulicp-allow-system-jobs`: pods labelled `kumulicp.io/component: system-job` (a new label on
    `HelmInstallJob` and every `Job/*JobChart`) may reach `network_api_server_cidrs` on 443/6443 and the internet
    (chart repositories, registries, vulnerability databases), even when the tier denies internet to apps.

What these do not cover, by design: kubelet health probes come from the node and most CNIs allow them regardless
of policy (CNI-specific, checked by the probe in Phase N2). Host-network pods aren't subject to NetworkPolicy.

### 2.5 App profile declarations

`AppProfile` gets an optional `protected $network` array, next to the `$security` array from the hardening plan:

```
network:
  ports: [{ name: http, port: 8080, protocol: TCP }]        # what the ingress controller and monitoring reach
  metrics_ports: [9205]
  egress: [internet, smtp, ldap, database]                  # which platform endpoints the app needs
  chart_policy:                                             # chart-shipped NetworkPolicy, if any
    enabled_path: networkPolicy.enabled
    neutralize: { networkPolicy.allowExternal: false, networkPolicy.allowExternalEgress: false }
  l7: [{ from: ingress, methods: [GET, POST, ...], paths: ["/*"] }]   # optional, Phase N5
```

`chart_policies: neutralize` on the tier applies the profile's `neutralize` values through the shared
`HelmChart` value merge, so it covers Rancher and `helm_k8s` at once. With `leave` (or with network policy off)
the chart default is untouched. Activation with an unknown chart policy (a generic app, or a chart that grew one)
is handled by `security.on_incompatible_app` from the hardening plan, plus a finding from the inventory below.

**Policy inventory**: on every reconcile, list all NetworkPolicies in the namespace. Any policy we don't own that
allows ingress from all namespaces or egress to everywhere becomes a high-severity finding ("this policy re-opens
your namespace"), because no amount of deny on our side can close it.

### 2.6 L7 provider plug-in contract

`App\Support\Security\Network\NetworkPolicyProvider`, registered in `NetworkPolicyProviderService` the same way
`SecurityToolService` registers scan tools, so a package or service provider can add one with `register()`:

```
key(): string                                   # 'istio'
capabilities(): [l7_http, mtls, identity, fqdn_egress, audit]
detect(Server): DetectionResult                 # CRDs present, version, mode; never writes
namespaceMetadata(intent): { labels, annotations }          # e.g. istio.io/dataplane-mode=ambient
baseLayerAdjustments(intent): NetworkIntent rules          # extra L4 allows the provider needs
manifests(intent): array<manifest>                          # its CRDs for this namespace
podSecurityImpact(options): ?level              # highest PSA level pods can keep under this provider
podTemplatePatches(kind: system-job|app): array # e.g. opt Jobs out of sidecar injection
requiredRbac(): array                           # rules to add to the deployer role / Rancher user
```

The reconciler writes the base layer first, then the provider's objects. A tier naming a provider the server
doesn't list in `network_l7_providers` (or that `detect()` doesn't find) applies the base layer only and records
a finding; it never fails activation.

### 2.7 Istio provider

Objects per org namespace:

- `PeerAuthentication kumulicp-mtls`: `PERMISSIVE` or `STRICT` from `l7_mtls`.
- `AuthorizationPolicy kumulicp-allow-nothing` (empty spec = deny all in the namespace), then `ALLOW` policies
  generated from the intent: same namespace by `source.namespaces`, the gateway by its service-account
  principal, monitoring by namespace. Profile `l7` rules add `to.operation` methods and paths.
- `Sidecar kumulicp-egress` (sidecar mode only): limits outbound hosts to the namespace, `istio-system` and
  declared `ServiceEntry` hosts, with `outboundTrafficPolicy: REGISTRY_ONLY` when the tier denies internet.
- `ServiceEntry` per external platform endpoint (database, LDAP, SMTP) so `REGISTRY_ONLY` still lets them through,
  by hostname rather than resolved IP.

Things that need to be handled, each found in Istio's documented behavior and to be confirmed on the pinned
version in Phase N4:

- **PSA interaction**: sidecar mode's `istio-init` needs `NET_ADMIN` and `NET_RAW`, which fails `baseline`.
  `podSecurityImpact()` reports `privileged` for sidecar mode without the Istio CNI agent, so the hardening plan's
  §2.4 clamp blocks it under a `baseline` or `restricted` tier. Ambient mode and sidecar mode with Istio CNI keep
  the app's own level, which is why ambient is the default.
- **Base layer adjustments**: ambient needs inbound HBONE (15008) from ztunnel and the probe source address Istio
  uses for kubelet probes; sidecar mode needs egress to `istiod` on 15012.
- **Gateway**: with `STRICT` mTLS, Traefik outside the mesh can't reach app pods. Either Traefik joins the mesh,
  or ingress moves to an Istio gateway, or `l7_mtls` stays `permissive`. The server option `gateway` records
  which; open question 2.
- **Jobs**: with sidecars (without native sidecars), Job pods never complete because the proxy keeps running.
  `podTemplatePatches(system-job)` adds `sidecar.istio.io/inject: "false"` to KumuliCP's Jobs. cert-manager
  HTTP-01 solver pods need the same, set on the ClusterIssuer's pod template, which is admin-owned; document it.
- **Rancher**: Istio CRDs go through Steve the same way Traefik Middlewares already do
  (`/v1/security.istio.io.authorizationpolicies`, and so on).

### 2.8 Verification (the NetworkPolicy equivalent of PSA warn/audit)

Kubernetes has no dry-run or audit mode for NetworkPolicy, and a CNI that doesn't enforce (plain flannel, for
example) accepts the objects and silently ignores them. So verification is active:

- **Enforcement probe** (`NetworkPolicyProbe`, per server): in a scratch namespace, start two minimal pods,
  apply a deny policy, and check that the connection fails and then succeeds when allowed. Pods use the
  hardening plan's `restricted` security context. Result: `enforced`, `not_enforced` or `error`, stored as a
  `SecurityScan` (tool `network-policy`).
- **Connectivity check** (per org namespace): a short Job, labelled like an app pod, tries DNS, each platform
  endpoint the intent allows, the internet (when allowed), and a canary pod in another tenant's namespace that must
  **fail**. Each unexpected result is a `SecurityFinding`. This is also the regression test after an upgrade.
- **Gate** (like `TierPreflightGate`): moving a plan to a tier with network policy requires an `enforced` probe
  on each affected server, and turning on `egress: restrict` requires a clean connectivity check against the
  rendered intent. The admin can tick "Apply anyway".
- **Rollback**: deleting `kumulicp-default-deny-*` restores the previous behavior instantly, and the allow
  policies are harmless on their own. The sync command gets `--remove-deny` for emergencies.

### 2.9 Rancher parity

| Concern | `helm_k8s` driver | Rancher driver |
|---|---|---|
| Write policies | `KubernetesApiClient::apply()`/`delete()`; add `NetworkPolicy` to `RESOURCES` | Steve `/v1/networking.k8s.io.networkpolicies` POST/PUT/DELETE |
| Permission | `networkpolicies` get/list/create/patch/delete in the deployer ClusterRole; `get` on `endpointslices` in `default` for API server discovery | The Rancher API user needs the same on the KumuliCP project's namespaces |
| Project isolation | n/a | Detect Rancher's project network isolation policies. With them present, `managed` refuses to claim tenant isolation and records a finding, since all orgs share one project; see open question 3 |
| L7 CRDs | `KubernetesApiClient` via discovery (CRD kinds are already discovered) | Steve paths per CRD |
| Probes | Jobs/Pods via `KubernetesApiClient` | The same manifests via the Rancher Job API |
| Chart policy neutralizing | Shared `HelmChart` value merge | Shared `HelmChart` value merge |

---

## 3. Phased implementation

### Phase N0 — Discovery (no cluster writes)

- Render each first-party chart and list the NetworkPolicies it ships (`scripts/security/netpol-inventory.py`,
  in the style of `psa-check.py`): Bitnami WordPress, the Redis subchart inside Nextcloud, CiviCRM once the chart
  is available. Flag allow-all rules.
- Write down each app's real flows (ports, metrics, outbound needs: app stores, federation, plugin updates, SMTP).
  This seeds the profile `network` arrays.
- Survey target clusters: which CNI enforces policy, whether the ingress controller uses `hostNetwork`, Rancher
  project isolation, Istio presence and mode.

Exit: a findings report `docs/k8s-network-policy-phase0.md` and the answers to the open questions.

### Phase N1 — Intent model and base ingress isolation (opt-in)

- `NetworkIntent`, `NetworkIntentBuilder`, `KubernetesNetworkPolicyProvider`, `NetworkPolicyReconciler` (pure
  diff, ownership by label like `NamespaceSecurityReconciler`).
- Tier `network` block with `ingress` and `chart_policies`, the `isolated` preset, and the plan editor field.
- Server settings `network_policy_mode`, `network_ingress_namespaces`, `network_ingress_cidrs`, validated on the
  server form and documented in both drivers' settings help (en/es).
- Both drivers write and reconcile on organization update, app activation and tier change, and
  `php artisan servers:sync-network-policy [--dry-run] [--organization=] [--server=]` backfills.
- Profile `network` declarations for Nextcloud and WordPress, and WordPress chart policy neutralizing.
- Policy inventory findings.
- RBAC sample and Rancher permission notes.

Tests: intent builder and renderer golden tests, reconciler unit tests, driver tests with `Http::fake`, feature
tests for tier/plan/server settings. Default output must be unchanged.

Exit: on a `managed` server with an enforcing CNI, a plan on `isolated` blocks other tenants' pods from its
namespace, and nothing changes with defaults.

### Phase N2 — Verification

- `NetworkPolicyProbe` and the per-namespace connectivity check, recorded as `network-policy` scans.
- The gate on tier changes, `servers:check-network-policy`, and an "unverified" badge on servers whose probe
  hasn't passed.

Exit: an admin can see, per server, whether policies are enforced and whether isolation holds.

### Phase N3 — Egress restriction

- `PlatformEndpointResolver` and policies 5–11 from §2.4.
- `kumulicp.io/component: system-job` on every KumuliCP Job (needed by `HelmInstallJob`, all `Job/*JobChart`
  classes, and `Nextcloud/Commands/RancherJob.php`), server settings for cluster and API server CIDRs.
- The `isolated-egress` preset, gated on a clean connectivity check.

Exit: an org's pods can reach only DNS, their declared platform endpoints, the ingress controller and (if allowed)
the internet; installs, upgrades and scans still work.

### Phase N4 — Pluggable L7, Istio provider

- `NetworkPolicyProvider` contract and `NetworkPolicyProviderService`.
- Istio provider (ambient first, sidecar with Istio CNI second), `network_l7_providers` server setting, tier
  `l7_provider` and `l7_mtls`, and `podSecurityImpact()` wired into the PSA clamp.
- Connectivity check extended to assert mTLS and identity rules (a plaintext call must fail under `STRICT`).

Tests: provider golden manifests, detection with faked discovery responses, and an optional `kind` job with Istio
ambient.

Exit: a plan on an Istio-backed tier gets mTLS and identity-based allow rules, with the base layer still in place.

### Phase N5 — Microsegmentation and app-level L7 rules

- `ingress: app`: per-app policies keyed on `app.kubernetes.io/instance`, so one org's WordPress can't reach its
  Nextcloud's Redis. Needs profiles to declare which apps legitimately call each other (CiviCRM and WordPress, for
  example).
- Profile `l7` rules rendered by providers that have `l7_http`.

### Backlog

- A second L7 provider. Cilium is the strongest candidate: `CiliumNetworkPolicy` gives L7 HTTP rules and
  `toFQDNs` egress, which fixes the stale-IP problem for hostname endpoints without a mesh.
- Use CNI flow logs (Hubble, Calico) through the provider's `audit` capability as a true observe mode.
- Per-org Rancher projects, if open question 3 goes that way.

---

## 4. Testing and verification

- **CI, no cluster**: renderer golden files for every preset, intent-builder tests over app combinations,
  `netpol-inventory.py` over rendered charts, and admin UI feature tests.
- **CI, optional e2e**: `kind` with Calico (enforcing CNI) running the probe and connectivity check against two
  org namespaces; a second job with Istio ambient for Phase N4.
- **Runtime**: the connectivity check after each release and on demand from the scans page.

## 5. Open questions

1. **CNIs in use.** Which CNI runs on your clusters (k3s's built-in policy controller, Calico, Canal, Cilium,
   something else)? It decides whether the base layer is enforced and which quirks to plan for.
2. **Istio shape.** Ambient (my default) or sidecar? And should ingress stay on Traefik (which then has to join
   the mesh for `STRICT` mTLS) or move to an Istio gateway?
3. **Rancher project isolation.** Is it on anywhere? If so, would you accept one Rancher project per organization,
   or should `managed` simply refuse to claim isolation on those clusters (my default)?
4. **KumuliCP's own location.** Does the control panel run inside the cluster and call app pods directly, or only
   through public URLs (my assumption)? If directly, its namespace needs an allow.
5. **Cross-org calls.** Do any org apps call shared-org apps from the server side (Collabora, for example)? Through
   the public URL they are covered by the hairpin allow; a direct Service call would need an explicit rule.
6. **Platform addresses.** Are database and LDAP addresses IPs or hostnames in practice? Resolving hostnames at
   reconcile time works but can go stale.
7. **Second provider.** Is Cilium (or Linkerd) worth planning for now, or Istio only?
8. **"Layer 5".** Is ports and protocols in the base layer plus mTLS identity in the provider layer what you meant
   by layer 4/5, or did you have something else in mind (for example TLS passthrough rules)?
9. **Internet egress default.** Should `isolated-egress` allow internet (my default, since Nextcloud's app store
   and WordPress updates need it) or deny it?

## 6. Risks

- **Silent non-enforcement.** A CNI without policy support accepts the objects and does nothing. The probe in N2
  exists for that, and the UI must never show "isolated" without a passing probe.
- **Additive semantics.** Any allow-all policy we don't own (chart defaults, Rancher project isolation) defeats our
  deny. The inventory and neutralizing in §2.5 mitigate it, but an admin can always add one.
- **Breaking installs and upgrades.** Egress restriction can cut the installer off from the API server or a
  registry. The system-job allow and the connectivity gate cover it; rollback is deleting one policy.
- **Stale IPs.** Hostname endpoints resolved into IPs break when the IP changes. Prefer explicit CIDRs, or a
  provider with FQDN egress.
- **Mesh side effects.** Istio sidecars can raise the PSA level a pod needs, hang Jobs, and break HTTP-01 solvers.
  Each is handled in §2.7, but they need confirming on a real cluster.
- **CNI quirks.** Some CNIs treat API server, node and host-network traffic differently from plain `ipBlock`
  rules. The probe catches these per cluster, but expect per-CNI notes in the docs.
