# Observability: logs, Loki / Alloy and alerting

KumuliCP can write its logs as one JSON object per line on **stderr**, so a container log
collector (Grafana Alloy, Promtail, Fluent Bit, ...) can ship them to Loki without any
files or sidecars. Alerting then runs from Loki / Grafana instead of from the app.

## 1. Configuration

All settings are environment variables (see `config/logging.php`).

| Variable | Default | Purpose |
|---|---|---|
| `LOG_CHANNEL` | `custom` | Keep as `custom`. (`.env.sample` ships `stack`, an older preset.) |
| `LOG_STACK` | `mysql,email,daily` | Comma list of channels behind `custom`. **Containers:** `stderr_json,mysql,email` |
| `LOG_LEVEL` | `info` / `debug` | Minimum level for `stderr_json` (default `info`) and `daily` (default `debug`) |
| `LOG_PROCESS` | *(auto)* | `web`, `queue` or `scheduler`. Shown as the `process` label. Set it per deployment |
| `LOG_HEARTBEAT` | `false` | `true` on the scheduler deployment: logs `scheduler.heartbeat` every minute |
| `LOG_DB_LEVEL` | `info` | Minimum level written to the `logs` table (admin → Logs). `warning` keeps it small |
| `LOG_DB_DAYS` | `90` | Days kept in the `logs` table (pruned daily) |
| `LOG_DAILY_DAYS` | `14` | Days of `storage/logs` files, if `daily` is in the stack |
| `APP_RELEASE` | | Free-form version string added to every record |

Recommended container profile:

```env
LOG_CHANNEL=custom
LOG_STACK=stderr_json,mysql,email
LOG_LEVEL=info
LOG_DB_LEVEL=warning
```

Without `daily` in the stack nothing is written under `storage/logs`, so the container can
run with a read-only root filesystem.

`stderr_json` deliberately uses **stderr**, not stdout: under FrankenPHP / Octane, output on
stdout before the worker is ready can corrupt the worker handshake. Collectors read both.

PHP settings for the image (php.ini / FPM pool):

```ini
log_errors = On
display_errors = Off
error_log = /dev/stderr
zend.exception_ignore_args = On   ; keeps argument values (secrets) out of stack traces
```

```ini
; php-fpm pool
catch_workers_output = yes
decorate_workers_output = no
```

## 2. Record format

```json
{
  "message": "Rancher application created",
  "context": {"organization_id": 3, "event": "messages.api.rancher.log.app_created", "exception": {"class": "...", "message": "...", "trace": ["..."]}},
  "level": 200,
  "level_name": "INFO",
  "channel": "local",
  "datetime": "2026-10-31T12:00:00.000000+00:00",
  "extra": {"service": "KumuliCP", "env": "production", "process": "queue", "host": "kumulicp-queue-7d9f", "request_id": "…", "organization_id": 3, "task_id": 41, "event": "…"}
}
```

* `extra.request_id`: set for every web request (an incoming `X-Request-Id` header is
  reused, and it is echoed back in the response). It follows the work into queued jobs.
* `extra.organization_id`, `user_id`, `route`: the acting user / organization.
* `extra.task_id`, `action_slug`, `app_instance_id`, `attempt`: set while a task runs.
* `extra.event`: a stable, locale-independent name. Use this (not `message`) in queries and
  alerts: log messages for some integrations are translated.
* Any context key that looks secret (`password`, `token`, `secret`, `api_key`, ...) is masked
  and strings over 2000 characters are truncated before they are written.

Notable events: `task.created`, `task.started`, `task.run_finished`, `task.connection_failed`,
`task.completed`, `task.failed`, `scheduler.heartbeat`, `scheduler.task_failed`,
`integration.request` (method, url without query, status, `duration_ms`),
`helm.command_failed`, `kubectl.command_failed`, `backup.job_failed`.

## 3. Kubernetes layout

Run the same image as separate workloads, each logging to stderr:

| Deployment | Command | `LOG_PROCESS` | Extra |
|---|---|---|---|
| `kumulicp-web` | php-fpm / Octane | `web` | |
| `kumulicp-queue` | `php artisan queue:work` | `queue` | |
| `kumulicp-queue-long` | `php artisan queue:work database-long --queue=long --tries=1 --timeout=900` | `queue` | |
| `kumulicp-scheduler` | `php artisan schedule:work` | `scheduler` | `LOG_HEARTBEAT=true`; run exactly one replica |

Label the pods `app.kubernetes.io/name: kumulicp`.

## 4. Alloy (Kubernetes)

Run Alloy as a DaemonSet (or use the Grafana k8s-monitoring chart) with something like:

```alloy
discovery.kubernetes "pods" {
  role = "pod"
}

discovery.relabel "kumulicp" {
  targets = discovery.kubernetes.pods.targets

  rule {
    source_labels = ["__meta_kubernetes_pod_label_app_kubernetes_io_name"]
    regex         = "kumulicp"
    action        = "keep"
  }
  rule {
    source_labels = ["__meta_kubernetes_namespace"]
    target_label  = "namespace"
  }
  rule {
    source_labels = ["__meta_kubernetes_pod_name"]
    target_label  = "pod"
  }
  rule {
    source_labels = ["__meta_kubernetes_pod_label_app_kubernetes_io_name"]
    target_label  = "app"
  }
}

loki.source.kubernetes "kumulicp" {
  targets    = discovery.relabel.kumulicp.output
  forward_to = [loki.process.kumulicp.receiver]
}

loki.process "kumulicp" {
  stage.json {
    expressions = {
      level           = "level_name",
      channel         = "channel",
      process         = "extra.process",
      event           = "extra.event",
      request_id      = "extra.request_id",
      organization_id = "extra.organization_id",
      task_id         = "extra.task_id",
    }
  }
  // Only low-cardinality values as labels...
  stage.labels {
    values = { level = "", channel = "", process = "" }
  }
  // ...and the rest as structured metadata: filterable, but not indexed.
  stage.structured_metadata {
    values = { event = "", request_id = "", organization_id = "", task_id = "" }
  }
  forward_to = [loki.write.default.receiver]
}
```

Never promote `request_id`, `organization_id` or `task_id` to labels: they have unbounded
cardinality. A working Docker version of this pipeline lives in
`docker/observability/alloy/config.alloy`; start it locally with:

```sh
docker compose -f docker-compose.yml.extras --profile observability up -d loki alloy grafana
```

Then open Grafana on http://localhost:3000 and query
`{service="laravel.test", level="ERROR"}`.

## 5. Example queries

```logql
# Follow one request across web and queue (structured metadata, no JSON parsing needed)
{app="kumulicp"} | request_id="3f1c…"

# Everything that happened to one organization
{app="kumulicp"} | organization_id="12"

# Failed tasks per hour
sum(count_over_time({app="kumulicp"} | event="task.failed" [1h]))

# Slow upstream calls (JSON fields are flattened: context.duration_ms -> context_duration_ms)
{app="kumulicp"} | event="integration.request" | json | context_duration_ms > 5000
```

## 6. Example alert rules (Loki ruler)

```yaml
groups:
  - name: kumulicp
    rules:
      - alert: KumuliCPErrorRateHigh
        expr: sum(count_over_time({app="kumulicp", level=~"ERROR|CRITICAL|ALERT|EMERGENCY"}[5m])) > 10
        for: 5m
        labels: {severity: warning}
        annotations: {summary: "More than 10 error-level log lines in 5 minutes"}

      - alert: KumuliCPTasksFailing
        expr: sum(count_over_time({app="kumulicp"} |= "task.failed" [15m])) > 0
        labels: {severity: warning}
        annotations: {summary: "A KumuliCP task failed"}

      - alert: KumuliCPUpstreamUnreachable
        expr: sum(count_over_time({app="kumulicp"} |= "task.connection_failed" [10m])) > 5
        labels: {severity: warning}
        annotations: {summary: "Tasks are repeatedly failing to reach Rancher / Helm / Authentik / Nextcloud"}

      - alert: KumuliCPSchedulerStopped
        expr: absent_over_time({app="kumulicp", process="scheduler"} |= "scheduler.heartbeat" [5m])
        for: 5m
        labels: {severity: critical}
        annotations: {summary: "No scheduler heartbeat for 5 minutes (needs LOG_HEARTBEAT=true)"}
```

Route these through Alertmanager / Grafana alerting to Slack, Matrix or email. Once they are
trusted, the app's own email and Matrix handlers can be dropped from `LOG_STACK`.

## 7. Notes

* The `logs` table and the Admin → Logs page keep working; set `LOG_DB_LEVEL=warning` to keep
  routine info messages out of the database now that Loki has them.
* Identical error alerts (email / Matrix) are sent at most once every 5 minutes, and an
  exception from the same code location is reported at most 5 times a minute.
* A task whose upstream is down is retried every minute and reported on the first failure and
  then every 30th attempt. `task.connection_failed` is logged on every attempt.

## 8. Audit trail

KumuliCP can keep a full audit trail of who did what. It is **off by default**: nothing is
recorded until an administrator turns on **Admin → Settings → Audit Log → Enable audit
logging** (the `audit_logging_enabled` server setting). Switching it on or off is itself
always recorded.

Where entries go:

* the append-only `audit_logs` table, browsable (filter, expand, export CSV) at
  **Admin → Server → Audit Log**;
* one JSON line per entry on the `audit` log channel, so Loki keeps an off-box copy that
  the application cannot alter. The channel is used automatically when `stderr_json` is in
  `LOG_STACK`; set `AUDIT_LOG_CHANNEL` to choose another channel or `null` to disable it.

Every entry has `event`, `outcome` (`success`, `failure`, `denied`), the actor (user or
organization, with the organization they belong to), the target organization, the subject,
a redacted before/after `diff`, the IP, request id and route.

What is recorded:

| Area | Events |
|---|---|
| Authentication | `auth.login`, `auth.logout`, `auth.login_failed` (identifier tried, never the password), `auth.lockout`, `auth.password_reset`, `auth.password_changed`, `auth.password_set`, `auth.password_reset_requested`, `auth.email_verified` |
| Settings | `settings.changed` (old and new value, secrets masked), `audit.enabled`, `audit.disabled` |
| Records | `<model>.created`, `<model>.updated`, `<model>.deleted` for users, organizations, plans, apps and versions, app instances, domains, servers, repo secrets, SSO providers, groups, backups and the other models listed in `config/audit.php` |
| Account manager | `account_user.created`, `account_user.updated`, `account_user.deleted` (also for LDAP users), `user.permissions_changed` (what was added, modified, removed) |
| Tasks | `task.created`, `task.completed`, `task.failed`, with whoever triggered the task |
| Requests | `http.request` for every write and every state-changing GET (remove, deactivate, restart, restore, install, ...) and every refused (403) request: method, route, bound records, status |
| Billing | `app_instance.subscription_changed`, `billing.webhook` (event type and id only) |
| Sensitive reads | `sso_provider.secret_viewed`, `audit.viewed`, `audit.exported` |
| Other | `organization.registered`, `domain.deleted` |

Passwords, tokens, API keys, secrets, request bodies and query strings are never stored;
attribute names that look secret are masked, and hidden model attributes are shown as
`[hidden]`.

Retention: keep entries forever, or set `AUDIT_RETENTION_DAYS`; old rows are pruned daily.

Example alerts (the stream is labelled `channel="audit"`):

```yaml
- alert: KumuliCPAuditSettingChanged
  expr: sum(count_over_time({app="kumulicp", channel="audit"} |= "audit.disabled" [5m])) > 0
  labels: {severity: critical}
  annotations: {summary: "Audit logging was switched off"}

- alert: KumuliCPLoginFailures
  expr: sum(count_over_time({app="kumulicp", channel="audit"} |= "auth.login_failed" [5m])) > 20
  labels: {severity: warning}
  annotations: {summary: "Many failed sign-ins in 5 minutes"}

- alert: KumuliCPAuditWriteFailed
  expr: sum(count_over_time({app="kumulicp"} |= "audit.write_failed" [5m])) > 0
  labels: {severity: critical}
  annotations: {summary: "Audit entries could not be written to the database"}

- alert: KumuliCPPackageInstalled
  expr: sum(count_over_time({app="kumulicp", channel="audit"} |= "http.request" |= "packages" [5m])) > 0
  labels: {severity: info}
  annotations: {summary: "Package manager activity (code install / upgrade)"}
```

Because the audit write failing must never break a request, it logs `audit.write_failed`
instead of throwing. Alert on it.
