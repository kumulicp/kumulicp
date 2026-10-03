#!/usr/bin/env bash
# Find the smallest set of Linux capabilities a container image needs to start.
#
# Starts from the container runtime's default capability set, then tries removing
# one capability at a time. A capability stays removed if the container still
# passes the smoke check. Result: a "required" list (keep) and a "droppable" list,
# which feed the app profile's capability metadata (see docs/k8s-security-plan.md).
#
# Needs a local Docker/Podman-compatible CLI. NOT run during authoring (no container
# runtime was available); verify on a machine that has one before trusting output.
#
# Usage:
#   capability-probe.sh [-s SECONDS] [-p CONTAINER_PORT] [-u USER] IMAGE [-- extra docker run args]
#
#   -s  seconds the container must stay running (default 15)
#   -p  also require an HTTP response (any status) on this container port
#   -u  run as this user (e.g. 33:33); default: the image's own user
#
# Example (Nextcloud apache image, as Kubernetes would run it):
#   capability-probe.sh -p 80 docker.io/library/nextcloud:33.0.2-apache -- -e SQLITE_DATABASE=nc

set -euo pipefail

RUNTIME="${CONTAINER_RUNTIME:-docker}"
WAIT=15
PORT=""
USER_ARG=()

# Capabilities in the default containerd / CRI-O / Docker runtime set.
DEFAULT_CAPS=(AUDIT_WRITE CHOWN DAC_OVERRIDE FOWNER FSETID KILL MKNOD NET_BIND_SERVICE NET_RAW SETFCAP SETGID SETPCAP SETUID SYS_CHROOT)

while getopts "s:p:u:" opt; do
  case "$opt" in
    s) WAIT="$OPTARG" ;;
    p) PORT="$OPTARG" ;;
    u) USER_ARG=(--user "$OPTARG") ;;
    *) exit 2 ;;
  esac
done
shift $((OPTIND - 1))
IMAGE="${1:?image required}"
shift
[[ "${1:-}" == "--" ]] && shift
EXTRA=("$@")

passes() { # passes CAP... -> 0 if the container starts and stays up with exactly these caps added back
  local args=(--cap-drop ALL --security-opt no-new-privileges)
  local cap
  for cap in "$@"; do args+=(--cap-add "$cap"); done
  [[ -n "$PORT" ]] && args+=(-p "127.0.0.1::${PORT}")

  local id
  id=$("$RUNTIME" run -d "${args[@]}" "${USER_ARG[@]}" "${EXTRA[@]}" "$IMAGE" 2>/dev/null) || return 1
  sleep "$WAIT"

  local ok=1
  if [[ "$("$RUNTIME" inspect -f '{{.State.Running}}' "$id" 2>/dev/null)" == "true" ]]; then
    ok=0
    if [[ -n "$PORT" ]]; then
      local mapped
      mapped=$("$RUNTIME" port "$id" "${PORT}/tcp" | head -1 | sed 's/.*://')
      curl -s -o /dev/null --max-time 5 "http://127.0.0.1:${mapped}/" || ok=1
    fi
  fi
  "$RUNTIME" rm -f "$id" >/dev/null 2>&1 || true
  return $ok
}

echo "Image: $IMAGE" >&2
echo "Baseline check (default capability set added back)..." >&2
if ! passes "${DEFAULT_CAPS[@]}"; then
  echo "FAIL: image does not start even with the default capability set; fix the smoke check/args first." >&2
  exit 1
fi

if passes; then
  echo "Image runs with --cap-drop ALL and nothing added back." >&2
  printf '{"image":"%s","required":[],"droppable":["ALL"]}\n' "$IMAGE"
  exit 0
fi

keep=("${DEFAULT_CAPS[@]}")
droppable=()
for cap in "${DEFAULT_CAPS[@]}"; do
  trial=()
  for k in "${keep[@]}"; do [[ "$k" != "$cap" ]] && trial+=("$k"); done
  echo "  trying without $cap..." >&2
  if passes "${trial[@]}"; then
    keep=("${trial[@]}")
    droppable+=("$cap")
  fi
done

join() { local IFS=,; echo "$*"; }
printf '{"image":"%s","required":["%s"],"droppable":["%s"]}\n' \
  "$IMAGE" "$(join "${keep[@]}" | sed 's/,/","/g')" "$(join "${droppable[@]}" | sed 's/,/","/g')"
