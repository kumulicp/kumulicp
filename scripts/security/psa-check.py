#!/usr/bin/env python3
"""Static Pod Security Standards (baseline / restricted) checker for rendered manifests.

Reads multi-document YAML (files or stdin, e.g. `helm template ... | psa-check.py -`)
and evaluates every pod-bearing resource against the Kubernetes Pod Security Standards
(https://kubernetes.io/docs/concepts/security/pod-security-standards/).

It approximates what the Pod Security Admission controller would report for the pod
template, so charts can be assessed without a cluster. It does NOT see anything a
mutating webhook or the chart's runtime behaviour changes, so confirm on a real
cluster (`warn=restricted` label) before relying on a result.

Usage:
    psa-check.py [--format md|json] [--workload-only] FILE [FILE ...]
    helm template r chart | psa-check.py -
"""

import argparse
import json
import sys

import yaml

BASELINE_CAPS = {
    "AUDIT_WRITE", "CHOWN", "DAC_OVERRIDE", "FOWNER", "FSETID", "KILL", "MKNOD",
    "NET_BIND_SERVICE", "SETFCAP", "SETGID", "SETPCAP", "SETUID", "SYS_CHROOT",
}
RESTRICTED_VOLUMES = {
    "configMap", "csi", "downwardAPI", "emptyDir", "ephemeral",
    "persistentVolumeClaim", "projected", "secret",
}
SAFE_SYSCTLS = {
    "kernel.shm_rmid_forced", "net.ipv4.ip_local_port_range", "net.ipv4.ip_unprivileged_port_start",
    "net.ipv4.tcp_syncookies", "net.ipv4.ping_group_range", "net.ipv4.ip_local_reserved_ports",
    "net.ipv4.tcp_keepalive_time", "net.ipv4.tcp_fin_timeout", "net.ipv4.tcp_keepalive_intvl",
    "net.ipv4.tcp_keepalive_probes",
}
SELINUX_TYPES = {"", "container_t", "container_init_t", "container_kvm_t", "container_engine_t"}


def pod_template(doc):
    """Return (pod_spec, pod_annotations) for a pod-bearing resource, else None."""
    kind = doc.get("kind")
    if kind == "Pod":
        return doc.get("spec") or {}, (doc.get("metadata") or {}).get("annotations") or {}
    if kind in ("Deployment", "StatefulSet", "DaemonSet", "ReplicaSet", "ReplicationController", "Job"):
        tpl = (doc.get("spec") or {}).get("template") or {}
    elif kind == "CronJob":
        tpl = ((((doc.get("spec") or {}).get("jobTemplate") or {}).get("spec") or {}).get("template")) or {}
    else:
        return None
    return tpl.get("spec") or {}, (tpl.get("metadata") or {}).get("annotations") or {}


def containers(spec):
    for key in ("containers", "initContainers", "ephemeralContainers"):
        for c in spec.get(key) or []:
            yield key, c


def check_pod(spec, annotations):
    """Yield (level, rule, subject, detail) for each violation."""
    pod_sc = spec.get("securityContext") or {}

    for flag in ("hostNetwork", "hostPID", "hostIPC"):
        if spec.get(flag):
            yield "baseline", "host namespaces", "pod", f"{flag}=true"

    for s in pod_sc.get("sysctls") or []:
        if s.get("name") not in SAFE_SYSCTLS:
            yield "baseline", "sysctls", "pod", f"unsafe sysctl {s.get('name')}"

    for v in spec.get("volumes") or []:
        kinds = [k for k in v if k != "name"]
        for k in kinds:
            if k == "hostPath":
                yield "baseline", "hostPath volumes", "pod", f"volume {v.get('name')}"
            if k not in RESTRICTED_VOLUMES:
                yield "restricted", "volume types", "pod", f"volume {v.get('name')} uses {k}"

    for k, val in annotations.items():
        if k.startswith("container.apparmor.security.beta.kubernetes.io/") and not (
            val == "runtime/default" or val.startswith("localhost/")
        ):
            yield "baseline", "AppArmor", "pod", f"{k}={val}"

    pod_nonroot = pod_sc.get("runAsNonRoot")
    pod_user = pod_sc.get("runAsUser")
    pod_seccomp = (pod_sc.get("seccompProfile") or {}).get("type")

    for kind, c in containers(spec):
        name = f"{kind[:-1] if kind.endswith('s') else kind}/{c.get('name')}"
        sc = c.get("securityContext") or {}
        caps = sc.get("capabilities") or {}
        add = {x.upper() for x in caps.get("add") or []}
        drop = {x.upper() for x in caps.get("drop") or []}

        if sc.get("privileged"):
            yield "baseline", "privileged", name, "privileged=true"
        extra = add - BASELINE_CAPS
        if extra:
            yield "baseline", "capabilities (add)", name, "adds " + ",".join(sorted(extra))
        for p in c.get("ports") or []:
            if p.get("hostPort"):
                yield "baseline", "host ports", name, f"hostPort {p['hostPort']}"
        if sc.get("procMount") not in (None, "Default"):
            yield "baseline", "/proc mount", name, f"procMount={sc['procMount']}"
        se = {**(pod_sc.get("seLinuxOptions") or {}), **(sc.get("seLinuxOptions") or {})}
        if se.get("type", "") not in SELINUX_TYPES or se.get("user") or se.get("role"):
            yield "baseline", "SELinux", name, f"seLinuxOptions {se}"
        profile = (sc.get("seccompProfile") or {}).get("type") or pod_seccomp
        if profile == "Unconfined":
            yield "baseline", "seccomp", name, "Unconfined"
        aa = (sc.get("appArmorProfile") or spec.get("securityContext", {}).get("appArmorProfile") or {}).get("type")
        if aa == "Unconfined":
            yield "baseline", "AppArmor", name, "Unconfined"

        # restricted
        if sc.get("allowPrivilegeEscalation") is not False:
            yield "restricted", "allowPrivilegeEscalation", name, "must be false"
        nonroot = sc.get("runAsNonRoot", pod_nonroot)
        if nonroot is not True:
            yield "restricted", "runAsNonRoot", name, "not true"
        user = sc.get("runAsUser", pod_user)
        if user == 0:
            yield "restricted", "runAsUser", name, "runs as UID 0"
        if (sc.get("seccompProfile") or {}).get("type", pod_seccomp) not in ("RuntimeDefault", "Localhost"):
            yield "restricted", "seccompProfile", name, "must be RuntimeDefault/Localhost"
        if "ALL" not in drop:
            yield "restricted", "capabilities (drop)", name, "does not drop ALL"
        if add - {"NET_BIND_SERVICE"}:
            yield "restricted", "capabilities (add)", name, "adds " + ",".join(sorted(add - {"NET_BIND_SERVICE"}))


def assess(docs):
    results = []
    for doc in docs:
        if not isinstance(doc, dict):
            continue
        tpl = pod_template(doc)
        if tpl is None:
            continue
        meta = doc.get("metadata") or {}
        violations = list(check_pod(*tpl))
        failing = {v[0] for v in violations}
        level = "privileged" if "baseline" in failing else ("baseline" if "restricted" in failing else "restricted")
        results.append({
            "workload": f"{doc.get('kind')}/{meta.get('name')}",
            "level": level,
            "violations": [{"level": a, "rule": b, "subject": c, "detail": d} for a, b, c, d in violations],
        })
    return results


def render_md(name, results):
    out = [f"### {name}", "", "| Workload | Passes | Violations |", "|---|---|---|"]
    for r in results:
        by_rule = {}
        for v in r["violations"]:
            by_rule.setdefault((v["level"], v["rule"]), []).append(v["subject"])
        text = "<br>".join(
            f"{lvl}: {rule} ({', '.join(sorted(set(subj)))})" for (lvl, rule), subj in sorted(by_rule.items())
        ) or "none"
        out.append(f"| {r['workload']} | **{r['level']}** | {text} |")
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("files", nargs="+", help="manifest files, or - for stdin")
    ap.add_argument("--format", choices=["md", "json"], default="md")
    args = ap.parse_args()

    report = {}
    for path in args.files:
        text = sys.stdin.read() if path == "-" else open(path).read()
        report[path] = assess(yaml.safe_load_all(text))

    if args.format == "json":
        json.dump(report, sys.stdout, indent=2)
    else:
        print("\n\n".join(render_md(p, r) for p, r in report.items()))


if __name__ == "__main__":
    main()
