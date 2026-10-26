# Kubernetes security — Phase 0 report

Part of `docs/k8s-security-plan.md`. Phase 0 changes no application behavior. It measures where today's
workloads stand and adds the tooling the later phases rely on.

## 1. What was and wasn't possible

The authoring environment had no Kubernetes cluster and no container runtime. Everything below is a **static
analysis of rendered manifests**, which approximates but does not replace a `warn=restricted` run on a real
cluster.

| Item | Status |
|---|---|
| Nextcloud chart | Rendered at the exact pinned tag `nextcloud-9.0.5` (the profile's recommendation). Subcharts (MariaDB/PostgreSQL/Redis) are the current Bitnami `main` versions, not the exact pins in `Chart.lock`, because the OCI registry rate-limited anonymous pulls. Collabora (off by default) was removed. |
| WordPress chart | Rendered from Bitnami `wordpress` 27.0.0. KumuliCP's WordPress profile has no recommended chart, and its values (`wordpressBlogName`, `extraEnvVars`, `updateStrategy`, `sidecars`) match this chart, but the version actually deployed is chosen per AppVersion by an admin. |
| CiviCRM chart | **Not covered.** It comes from `repo.kumuli.dev`, which isn't reachable from here. |
| KumuliCP values | KumuliCP sets no security values for any chart, so chart defaults equal what is deployed for security fields. Non-security values were approximated (external database, Redis, cron, Imaginary and metrics on). |
| Capability probing | Not run (needs Docker). The probe script is written and syntax-checked only. |
| Rancher | No Rancher instance. Rancher parity is analysed from code in the plan (§2.7). |
| Tooling | `helm` v3.16.4 was built from source in a scratch directory. It is not committed. |

## 2. Rendered chart results

Checker: `scripts/security/psa-check.py` (rules from the Pod Security Standards; sanity-tested against
known-good, known-bad and middle-ground fixture pods).

### Nextcloud 9.0.5 (apache image `nextcloud:33.0.2-apache`)

| Workload | Highest level passed | Why not restricted |
|---|---|---|
| Deployment `nextcloud` (containers `nextcloud`, `nextcloud-cron`) | **baseline** | No `allowPrivilegeEscalation: false`, no `drop: ALL`, no `runAsNonRoot`, no seccomp profile. Only `fsGroup: 33` is set, at pod level. |
| Deployment `nextcloud-imaginary` | **baseline** | Already runs as 1000/non-root. Missing `allowPrivilegeEscalation: false`, `drop: ALL` and seccomp. |
| Deployment `nextcloud-metrics` | **baseline** | Same as Imaginary. |
| StatefulSets `redis-master`, `redis-replicas` | **restricted** | n/a (Bitnami defaults) |

Nothing fails `baseline`. That supports decision 6: Nextcloud's namespaces can be enforced at `baseline` today
without touching the chart, and Imaginary/metrics/Redis could be raised to restricted-level settings through
chart values.

### Bitnami WordPress 27.0.0

| Workload | Highest level passed |
|---|---|
| Deployment `wordpress` (plus init container `prepare-base-dir`) | **restricted** |
| Same, with the MariaDB subchart enabled (`mariadb` StatefulSet) | **restricted** |

Bitnami's defaults (`runAsNonRoot`, UID 1001, `drop: ALL`, `readOnlyRootFilesystem`, seccomp `RuntimeDefault`) pass
as-is. This only holds if the admin's configured image is the Bitnami image the chart expects; a different image
under this chart may break at UID 1001.

### Verified chart value paths for Phase 3

Read from the actual `values.yaml`/templates, not assumed.

**Nextcloud 9.0.5** (all default `{}` unless noted):
`nextcloud.securityContext` (main container only), `nextcloud.podSecurityContext`,
`nextcloud.mariaDbInitContainer.securityContext`, `nextcloud.postgreSqlInitContainer.securityContext`,
`nginx.securityContext`, `cronjob.sidecar.securityContext`, `cronjob.cronjob.securityContext`,
`imaginary.securityContext` (default `runAsNonRoot: true, runAsUser: 1000`), `imaginary.podSecurityContext`,
`metrics.securityContext` (same default), `metrics.podSecurityContext`, `rbac.enabled`. The chart has **no**
`networkPolicy` value and no `automountServiceAccountToken` value.

**Bitnami WordPress 27.0.0**: `podSecurityContext`, `containerSecurityContext`, `metrics.containerSecurityContext`,
`automountServiceAccountToken` (default false), `serviceAccount.automountServiceAccountToken`,
`networkPolicy.*` (**enabled by default**, `allowExternal: true`, `allowExternalEgress: true`).

## 3. KumuliCP-owned workloads (read from code)

| Workload | Current security settings | Under `restricted` |
|---|---|---|
| `HelmInstallJob` (`alpine/helm`, `helm_k8s` only) | none | Fails: runs as root, no seccomp profile, no `drop: ALL`, no `allowPrivilegeEscalation: false`. It also has a writable root filesystem. |
| `SecurityScanJobChart` (all tools) | none | Fails restricted for all tools. |
| `NextcloudJobChart` | pod: `fsGroup 33`, `runAsUser 33`, and `allowPrivilegeEscalation: true` | The last field belongs on the container. It is ignored or rejected depending on API validation. Needs a container-level `allowPrivilegeEscalation: false`, `drop: ALL`, seccomp. |
| `WordpressJobChart` | pod: `fsGroup 0` | `fsGroup 0` isn't blocked by PSA, but `runAsNonRoot` and the rest are missing. |
| `Nextcloud/Commands/RancherJob.php` | same as `NextcloudJobChart`, including the misplaced field | Same fix. |
| `MySQLJobChart` | none | Needs the full set. |
| Profile sidecars (`Sidecar` classes) | none defined in the repo today | n/a |

## 4. Findings worth acting on

1. **Misplaced field**: `NextcloudJobChart` (and `Nextcloud/Commands/RancherJob.php`) put `allowPrivilegeEscalation`
   in the pod `securityContext`. With `kubectl` strict field validation (`helm_k8s` driver) this can fail the
   apply. Fix in Phase 2 regardless of the toggle, since it is a bug rather than a security-posture change.
2. **`kube-bench` can't do its job**: its Job has no `hostPID` or host mounts, so it can't read node config.
   Matches decision 3 (give it privileges in a dedicated namespace). `kube-hunter` in `--pod` mode needs none.
3. **WordPress already ships a NetworkPolicy** (Bitnami default `networkPolicy.enabled: true`). Phase 5's
   NetworkPolicy must coexist with it.
4. **Image availability**: Bitnami moved older images to `docker.io/bitnamilegacy`. The Redis subchart in the
   Nextcloud render pulls `bitnamilegacy/redis`. Pinned tags in profiles (`8.0.1-debian-12-r1`) may stop being
   pullable. Outside this plan's scope, but it affects reproducibility.
5. **Nextcloud default**: only `fsGroup: 33` is set. `securityContext` is empty, so the main container's user
   depends on the image (root at start, then Apache drops to `www-data`).

## 5. Capability notes (unverified hypotheses)

The runtime default set is: `AUDIT_WRITE, CHOWN, DAC_OVERRIDE, FOWNER, FSETID, KILL, MKNOD, NET_BIND_SERVICE,
NET_RAW, SETFCAP, SETGID, SETPCAP, SETUID, SYS_CHROOT`. By its design the Nextcloud apache image starts as root,
fixes ownership, then drops privileges. That suggests `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETUID`, `SETGID`,
`KILL` and `NET_BIND_SERVICE` are likely required, and `NET_RAW`, `MKNOD`, `SETFCAP`, `SETPCAP`,
`AUDIT_WRITE`, `FSETID` and `SYS_CHROOT` are likely droppable. **This is a guess from how the image works, not a
measurement.** Run the probe before encoding it in a profile:

```
scripts/security/capability-probe.sh -p 80 docker.io/library/nextcloud:33.0.2-apache -- -e SQLITE_DATABASE=nc
```

Note that adding back anything beyond `NET_BIND_SERVICE` keeps a workload at `baseline`, and adding anything
outside the baseline set makes it `privileged`. See plan §2.6.

## 6. Reproducing the matrix

Vendor the subcharts by copying `bitnami/{common,mariadb,memcached,postgresql,redis}` from the Bitnami repo into
each chart's `charts/` directory (and each Bitnami subchart gets its own copy of `common`), then:

```
helm template nc ./nextcloud -f nc-values.yaml | scripts/security/psa-check.py -
helm template wp ./wordpress -f wp-values.yaml | scripts/security/psa-check.py -
```

Values used for Nextcloud: external database on, internal DB/MariaDB/PostgreSQL off, Redis (auth, one replica),
cron, Imaginary and metrics on, HPA off. For WordPress: MariaDB off, external database set.

## 7. Next steps before Phase 1

1. On a machine with a cluster: run the same charts into a namespace labelled
   `pod-security.kubernetes.io/warn=restricted` (and `baseline`) and compare against §2.
2. On a machine with Docker: run the capability probe for the Nextcloud, Imaginary, metrics-exporter and
   WordPress images, and record `required`/`droppable` in the profiles (Phase 3).
3. Provide the CiviCRM chart so it can be added to the matrix.
4. Confirm the plan's open questions (plan §5).
