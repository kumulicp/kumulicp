# Plan: Full Ticket Support

Status: proposal. Nothing here is built yet.

## 1. Where we are today

| What exists | Where | Limitation |
|---|---|---|
| Help-desk modal (subject, body, type: question/bug/feature) | `resources/js/components/navbar/components/modals/HelpDeskModal.vue` | Fire-and-forget; no history |
| Submit endpoint | `Account\Support@submit` (`POST /support/ticket/submit`) | Emails `support_email` via the `errors` mailer and stores nothing. Body is capped at 1000 chars |
| Minimal TinyMCE editor | `HelpDeskTinymceEditor.vue` (lists, link, bold/italic/underline) | Good starting point for ticket editing |
| Full TinyMCE wrapper | `components/FormInputs/TinymceEditor.vue` | Uses `laravel-filemanager`, which is a *shared* folder with a relaxed CSP. **Must not be used for tickets** (see §6) |
| Panel + mail notifications | `PanelChannel`, `App\Notification`, `Account\Notifications@index`, e.g. `NewAnnouncement` | Panel notifications have no deep link (`url`) yet |
| Extensibility patterns | `BillingDriverRegistry`, `AppProfileRegistry`, `nwidart/laravel-modules`, `Integration` base class | Reuse for tracker drivers; they are Octane-safe singletons |
| Feature flags | `config/toggle.php` + `EnsureFeatureEnabled` middleware | Use to roll tickets out behind a flag |
| Audit timeline pattern | `TaskEvent` (append-only) | Mirror for `ticket_events` |
| Secrets handling | `SecretValue` (encrypt), `Redactor` (mask) | Use for integration credentials and delivery logs |
| Queue + scheduler | `QUEUE_CONNECTION`, `routes/console.php` | Queue jobs for delivery; scheduler for SLA/auto-close/cleanup |
| Broadcasting | driver is `null`, no Reverb/Pusher installed | Hence polling now, WebSockets later |
| Users | LDAP-backed; `users` table mirrors them; panel notifications key on `username` | Ticket authors need both `user_id` and a username-based notification path |
| Admin gate | `Gate::define('admin')` → `hasControlPanelAdminAccess()` | Too coarse for support agents (§4) |

## 2. Goals and non-goals

**Goals (from the request):** orgs can list, create, view, reply to, and edit their tickets; admins can list, view, and respond; TinyMCE rich text; attachments; polling for new replies; notifications on replies; extensible webhooks/integrations that push issues to GitHub, GitLab and similar tools for time and issue tracking.

**Added after review (section 11):** internal notes, SLA, canned responses, ticket context (org/app/server state), email threading and inbound replies, CSAT, merge, time entries, reporting, retention and privacy, API, and security hardening.

**Non-goals for v1:** live chat, WebSocket push, a public knowledge base, multi-brand/multi-queue routing, voice/phone.

## 3. Data model

All tables are new migrations (`2026_11_*`). `organization_id` is on every row that can be queried from the org side.

```
tickets
  id, number (unique, human: "KCP-1042"), organization_id, requester_id (users.id),
  assignee_id (users.id, null), subject, type (question|bug|feature),
  status (enum, §3.1), priority (low|normal|high|urgent), impact (requester-supplied: minor|degraded|outage),
  category_id, app_instance_id (null), source (web|email|api|system),
  first_response_at, last_activity_at, last_requester_reply_at, last_staff_reply_at,
  resolved_at, closed_at, due_at, sla_first_response_due_at, sla_resolution_due_at,
  merged_into_id (null), csat_score/csat_comment (null), lock_version, deleted_at, timestamps
  indexes: (organization_id, status, last_activity_at), (assignee_id, status), (number)

ticket_messages
  id, ticket_id, author_id, author_kind (requester|staff|system),
  visibility (public|internal), body_html (sanitized), body_text (derived, searchable),
  source, email_message_id (null), edited_at, redacted_at, deleted_at, timestamps
  index: (ticket_id, id)

ticket_message_revisions     id, message_id, body_html, edited_by, created_at   (edit history)
ticket_attachments           id, ticket_id (null until sent), message_id (null), uploader_id, disk, path,
                             original_name, mime, size, sha256, scan_status, inline (bool), orphaned_after
ticket_events                id, ticket_id, actor_id, type, from, to, context json, created_at  (append-only timeline)
ticket_reads                 ticket_id, user_id, last_read_message_id, read_at  (unread badges)
ticket_watchers              ticket_id, user_id, notify_mail, notify_panel      (CC / followers)
ticket_time_entries          id, ticket_id, user_id, minutes, note, billable, worked_at, external_ref json
ticket_categories            id, name, slug, default_assignee_id, default_priority, sla_policy_id, active, sort
ticket_tags / ticket_tag     simple tags, many-to-many
ticket_canned_responses      id, title, body_html, scope (shared|personal), owner_id
ticket_sla_policies          id, name, targets per priority (first response mins, resolution mins), business_hours json
ticket_integrations          id, driver, name, config (encrypted json), enabled, direction (out|two_way),
                             filters json, events json, consecutive_failures, disabled_reason
ticket_external_links        id, ticket_id, integration_id, external_id, external_url, external_state, last_synced_at
ticket_deliveries            id, integration_id, ticket_id, event, idempotency_key (unique), status,
                             attempts, response_code, request/response (redacted), next_attempt_at
```

### 3.1 Status model

`open` → `pending_support` ⇄ `pending_customer` → `on_hold` → `resolved` → `closed`.

- Staff reply → `pending_customer`. Requester reply → `pending_support`. A reply to a `resolved` ticket reopens it.
- `resolved` auto-closes after N days (setting). `pending_customer` gets a reminder, then auto-resolves.
- A `TicketStatus` backed enum (like `TaskStatus`) with `isOpen()`, `allowedTransitions()`, and the transition rules kept in one `TicketWorkflow` service. Controllers never set `status` directly.

## 4. Authorization and tenancy

- **Org side:** `TicketPolicy`. A requester sees their own tickets; org owners/billing managers see all tickets for the org (and parent orgs see child orgs, using the existing `view-organization` gate). Suggest a per-org setting "all members can see all org tickets" (default off).
- **Admin side:** add a `manage-tickets` gate rather than reusing `admin`, so support staff do not need full control-panel admin. Initially it resolves to `admin`; later it can map to a dedicated permission via `PermissionsManager`.
- **Isolation tests are mandatory:** route-model binding is scoped by `organization_id` (`Ticket::forOrganization()`), and a Pest test per endpoint asserts that org B gets 404 for org A's ticket, message, and attachment.
- **Internal notes never reach org-side responses.** Org and admin controllers use separate API Resource classes. The org-side query always filters `visibility = public`, and a test asserts no internal text appears in any org payload (including poll responses and notification bodies).
- Roles: requester, watcher, agent, admin. Agents can be restricted to categories (later).

## 5. Backend structure

```
app/Ticket.php, TicketMessage.php, ...        models (flat, matching the repo's convention)
app/Enums/TicketStatus.php, TicketPriority.php, TicketType.php
app/Services/Tickets/TicketService.php        create / reply / edit / assign / merge (transactional, emits events)
app/Services/Tickets/TicketWorkflow.php       transitions + SLA clock
app/Services/Tickets/TicketNumberGenerator.php
app/Services/Tickets/AttachmentService.php
app/Services/Tickets/HtmlSanitizer.php        wraps mews/purifier "ticket" profile
app/Http/Controllers/Account/Tickets.php      org side (existing string-controller route style)
app/Http/Controllers/Account/Tickets/{Messages,Attachments,Poll}.php
app/Http/Controllers/Admin/Tickets.php        + Admin/Tickets/{Messages,TimeEntries,Integrations,...}
app/Http/Requests/Tickets/*                   form requests
app/Http/Resources/Tickets/{Org,Admin}*.php   separate resources per audience
app/Policies/TicketPolicy.php
app/Events/Tickets/*                          TicketCreated, MessageAdded, StatusChanged, Assigned, ...
app/Notifications/Tickets/*                   (§8)
app/Jobs/Tickets/*                            delivery, inbound mail, sweeps
```

- All writes go through `TicketService`, inside a DB transaction. Events are dispatched after commit (`afterCommit`).
- Optimistic concurrency: `lock_version` on ticket updates (agent edits) and on message edits; a stale write returns 409 and the UI shows "this ticket changed, reload".
- Rate limiting: `RateLimiter` on create (for example 10/hour/user) and reply (30/hour/ticket), configurable.
- Scoped services only; do not keep per-request state in singletons (Octane is in use, see `BillingDriverRegistry` for the one correct exception).
- Feature flag `tickets` in `config/toggle.php` (`TOGGLE_TICKETS`), applied via the existing `EnsureFeatureEnabled` middleware and `MenuService`. With the flag off, the old modal and email behaviour is untouched.

## 6. Rich text with TinyMCE

**Editor (`resources/js/components/tickets/TicketEditor.vue`)**
- Build on `HelpDeskTinymceEditor.vue`, not the filemanager-based editor. Extend its plugin list: `lists advlist link autolink codesample paste` (as bundled in TinyMCE 8) plus `image` (paste/drop only, no file browser).
- Toolbar: `blocks | bold italic underline strikethrough | bullist numlist | blockquote codesample code | link image | removeformat | undo redo`. Code blocks matter for tech support (logs, YAML).
- Menubar off, statusbar off, `paste_data_images: true`, `images_upload_handler` → attachment endpoint (§7) so pasted screenshots become inline attachments, not base64.
- Reuse the `content_css` manifest loader from `TinymceEditor.vue` by extracting it into a composable (it is currently duplicated per component), so content matches the app theme and dark mode.
- Draft autosave to `localStorage` per ticket (guarded by try/catch), restored with a "Restore draft?" prompt. Warn on navigating away with unsent content.
- Quote-reply button inserts a `<blockquote>` of the selected message.
- Accessibility: keyboard-reachable toolbar, label bound to the editor, `aria-live` region for new replies.
- Lazy-load the editor with `defineAsyncComponent` (the modal already does) to keep bundle size down.

**Sanitization (the real security boundary)**
- Never trust client HTML. Add a `ticket` profile to `config/purifier.php`: `p, br, strong, em, u, s, a[href|title], ul, ol, li, blockquote, pre, code, img[src|alt|width|height], h3, h4`; no inline style, no iframes/video.
- Links get `rel="noopener noreferrer nofollow"` and `target="_blank"`; only `http(s)`/`mailto` schemes.
- `img[src]` is restricted to our own attachment URL pattern, so no remote tracking pixels or external image fetches by staff.
- Store `body_html` (sanitized) plus `body_text` (stripped) for search, email text parts, and tracker payloads. Sanitize on write *and* render via a single path; render in Vue with `v-html` only on sanitized output (CSP already blocks inline script).
- Drop the existing 1000-char limit. Replace with a configurable cap (for example 50k chars of text) and show a counter.
- XSS regression tests: script tags, `javascript:` URLs, `onerror` handlers, SVG data URIs, nested/malformed HTML, and unicode tricks.

## 7. Attachments

- **Storage:** new private disk `tickets` in `config/filesystems.php` (local by default, S3-compatible optional). Never under `public/`. Random object names (`tickets/{org}/{ticket}/{uuid}`); the original filename is metadata only.
- **Upload flow (two step):** `POST /support/tickets/uploads` accepts a file and returns a draft attachment `{id, name, size, url}` owned by the uploader, with `ticket_id = null`. Creating/replying with `attachment_ids[]` binds them. Benefits: progress bars, paste-image support, and nothing is lost on a validation failure. A scheduled job deletes unbound uploads after 24 hours.
- **Validation:** size limits (default 10 MB per file, 25 MB per message, 10 files, all admin-settable and bounded by `php.ini` / proxy limits; docker `php.ini` is currently 100M, check the ingress body size too); MIME detected server-side with `finfo` (never trust the extension or client MIME); allow-list (images, pdf, txt/log, csv, zip, office docs, yaml/json); block executables, scripts, and html/svg uploads as attachments (SVG is sanitized or served as download only).
- **Serving:** `GET /support/tickets/{n}/attachments/{id}` goes through `TicketPolicy`, streams from the private disk, sends `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment` (inline only for raster images and PDFs with a sandboxing CSP), and short-lived signed URLs for emailed links.
- **Safety:** sha256 recorded; optional `AttachmentScanner` interface with a ClamAV implementation behind a setting (`scan_status`: pending/clean/infected, infected files are quarantined and agents warned); image thumbnails with `intervention/image` (already a dependency); EXIF stripped.
- **Quotas and retention:** optional per-org storage cap; files deleted with the ticket purge and when an attachment's message is redacted.
- Admin side uses the same endpoints/components; agents can also attach to internal notes.

## 8. Polling and notifications

### 8.1 Polling (no WebSocket dependency)
- `GET /support/tickets/{n}/poll?after=<last_message_id>&v=<etag>` returns new messages, status/assignee changes, and typing/"agent viewing" hints later. It returns `304` when nothing changed. Same shape on the admin side with its own resource.
- Cheap on the server: a per-ticket cache "version" key bumped on any write, checked before touching the DB; indexed query `(ticket_id, id) > after` otherwise.
- Client composable `useTicketPolling()`: 10 s while the tab is visible, 60 s when hidden (Page Visibility API), exponential back-off on errors, stops when the ticket is closed, resumes on focus. Appends new messages to local state rather than reloading Inertia props, so an unsent draft is never clobbered. Inertia's `usePoll` is a fallback for list pages.
- List polling: `GET /support/tickets/summary` returns unread count + changed ticket ids; drives the nav badge and list refresh. It can piggy-back on the existing notification poll that powers the bell.
- Unread tracking: `ticket_reads.last_read_message_id` updated when a ticket is opened or polled while visible.
- **Upgrade path:** `TicketMessageAdded` is written so it can later `implements ShouldBroadcast` on a private `ticket.{id}` channel (and `routes/channels.php`), with the client swapping the poll for Echo without UI changes.

### 8.2 Notifications
Laravel `Notification` classes, queued, following `NewAnnouncement` (`PanelChannel` + `mail`).

| Event | Requester / watchers | Staff |
|---|---|---|
| Ticket created | confirmation (mail) | to assignee, or the support team if unassigned |
| Public staff reply | mail + panel | none |
| Requester reply | none | assignee (or team) mail + panel |
| Internal note | never | assignee / mentioned agents |
| Status change / resolved | mail + panel (resolved includes "reopen" link and CSAT) | n/a |
| Assignment | n/a | new assignee |
| SLA at risk / breached | n/a | assignee + lead |
| Auto-close / pending reminder | mail | n/a |

- Extend `PanelChannel`/`Account\Notifications@index` to carry a `url` so a panel notification deep-links to the ticket.
- Per-user preferences (mail on/off per event type, stored on a `notification_preferences`-style JSON on the user) and per-ticket mute.
- **Coalescing:** replies in rapid succession send one email (queue with a ~1 minute delay and skip if the recipient already read it via `ticket_reads`).
- **Email threading:** `Message-ID` / `In-Reply-To` / `References` headers, subject `[KCP-1042] ...`, `Reply-To` as plus-address (`support+KCP-1042.<token>@domain`) for inbound (§9).
- Replace `Mail\SupportTicket`'s use of the `errors` mailer for staff alerts with the default mailer (keep `errors` as fallback for the legacy path).
- Notification content for email/panel is built from sanitized `body_text` excerpts; internal notes never appear in any requester-facing channel (tested).

## 9. Integrations and webhooks (extensible)

### 9.1 Two layers
1. **Generic outbound webhooks.** HMAC-signed JSON POSTs for anything (n8n, Zapier, Slack-compatible incoming hooks, custom services).
2. **Tracker drivers.** Typed drivers that create and sync issues in GitHub, GitLab, and similar tools.

### 9.2 Driver contract

```php
interface TicketTrackerDriver
{
    public static function key(): string;                 // 'github', 'gitlab', 'jira', 'linear', ...
    public function label(): string;
    public function configSchema(): array;                // fields: type, label, secret?, required, help; drives the admin form
    public function capabilities(): array;                // create_issue, comment, state_sync, time_tracking, labels, assignees, attachments, inbound_webhook
    public function testConnection(IntegrationConfig $c): TestResult;
    public function createIssue(IntegrationConfig $c, TicketPayload $p): ExternalIssue;
    public function addComment(IntegrationConfig $c, ExternalIssue $i, MessagePayload $m): void;
    public function updateState(IntegrationConfig $c, ExternalIssue $i, TicketPayload $p): void;
    public function logTime(IntegrationConfig $c, ExternalIssue $i, TimeEntryPayload $t): void;   // if capable
    public function verifyInbound(Request $r, IntegrationConfig $c): bool;                         // signature/token
    public function parseInbound(Request $r, IntegrationConfig $c): ?InboundEvent;
}
```

- `TicketIntegrationRegistry` is a true singleton (same Octane reasoning as `BillingDriverRegistry`). Built-in drivers register in a service provider; **third parties ship drivers as `nwidart/laravel-modules` modules** that call `TicketIntegrations::register()`; no core changes needed.
- `configSchema()` renders the admin form generically (like plan features/configurations), so a new driver needs no Vue work. Secret fields are encrypted with `SecretValue`, never returned to the browser (masked via `Redactor`), and only replaced when a new value is submitted.
- `TicketPayload`/`MessagePayload` are driver-neutral DTOs carrying subject, `body_markdown` (converted from sanitized HTML with `league/html-to-markdown`, to be added), `body_html`, `body_text`, labels, ticket URL, and org name. Jira (ADF) and others convert from these.

### 9.3 Built-in drivers (priority order)
1. **Generic webhook** (signed JSON, any URL).
2. **GitHub Issues** (token or GitHub App; issue + comments + labels + state; time logged as a structured comment since GitHub has no native time tracking).
3. **GitLab Issues** (self-hosted or gitlab.com; supports native time tracking via `/spend` and time stats).
4. **Jira** (issue + worklog API for time), **Linear**, then Gitea/Forgejo, Azure DevOps, Redmine, Plane, ClickUp, Trello, Asana, and time tools such as Clockify/Toggl/Harvest as "time" drivers. All added later without core changes.

### 9.4 Behaviour
- **Triggers:** manual "Create issue in…" button in the admin ticket view, and optional auto-rules per integration (type, category, priority, org plan, tag).
- **Events delivered:** `ticket.created`, `message.created` (public, and internal only if marked "share with tracker"), `status.changed`, `assigned`, `time.logged`, `ticket.merged`.
- **Delivery:** a queued `DeliverTicketIntegration` job per (integration, ticket, event). Unique `idempotency_key`, exponential backoff, honour `429`/`Retry-After`, per-integration circuit breaker (disable and notify admins after N consecutive failures), and a delivery log with redacted request/response and a "redeliver" button. Order per ticket is preserved by serializing jobs per `(integration, ticket)`.
- **Webhook signature:** `X-KCP-Signature: t=<unix>,v1=<hmac_sha256(secret, t.body)>`, plus `X-KCP-Event` and `X-KCP-Delivery`. Secret rotation supports two active secrets during a grace period.
- **Inbound / two-way (phase 5):** `POST /webhooks/tickets/{integration}/{token}` (no session; verified by the driver, for example GitHub `X-Hub-Signature-256`, GitLab `X-Gitlab-Token`). Maps issue closed/reopened/commented to an internal note or status change. **Loop prevention:** ignore events authored by our integration identity and events whose `external_id` we already recorded.
- **Privacy defaults:** only subject, public messages, org name, and ticket URL leave the system. Requester name/email, internal notes, and attachment bytes are off by default and opt-in per integration (attachments sent as links or uploaded where the tracker supports it).
- **SSRF hardening** (generic webhook and self-hosted tracker URLs are admin-supplied): https only (http requires an explicit toggle), DNS resolution checked against private/loopback/link-local/metadata ranges **unless** the admin enables "allow internal hosts" for self-hosted GitLab in the cluster, no redirects by default, 10 s timeout, response size cap, and no reflection of response bodies to non-admins.
- **Time and issue tracking, as requested:** tickets own *customer-facing* conversation; the tracker owns *engineering work*. Built-in `ticket_time_entries` let agents log time on the ticket, and drivers with `time_tracking` mirror it (GitLab `/spend`, Jira worklog). The external issue's key and state are shown on the ticket (`ticket_external_links`), and a ticket can link several issues.
- **Admin UI:** `Admin/Settings/TicketIntegrations` (list, add by driver, test connection, filters/rules, enable/disable, delivery log, redeliver, rotate secret).

## 10. UI

### Org side (`resources/js/Pages/Organization/Tickets/`)
- **List:** tabs (Open / Waiting on us / Waiting on you / Resolved / All), search, filter by type/app, unread dots, "mine vs organisation".
- **Create:** subject, type, category, related app (dropdown of the org's app instances), impact, rich text, attachments, CC colleagues. Show relevant active **announcements** and the documentation link before submit (deflection). Detect pasted secrets (password/API-key patterns) and warn.
- **Detail:** threaded conversation (public only), status chip, attachments list, reply composer, quote reply, edit own message (until a staff reply or a time window, with "edited" badge and revision history kept), close/reopen, watchers, CSAT prompt on resolve, "new replies" banner driven by polling.
- **Entry points:** sidebar item with unread badge (via `MenuService`), and the existing `?` help-desk icon becomes "New ticket" (modal reused for quick create → redirects to the created ticket).

### Admin side (`resources/js/Pages/Admin/Tickets/`, under `/admin/support`)
- **Inbox:** saved views (Unassigned, Mine, All open, Overdue/At risk, Waiting on customer, Closed), column sort, filters (org, assignee, status, priority, category, tag, app, SLA state), full-text search, bulk actions (assign, status, priority, tag, close, merge).
- **Detail:** conversation with a public-reply / internal-note toggle, canned-response picker with variables (`{{requester.name}}`, `{{org.name}}`), assignee/priority/status/category/tag controls, time-entry panel, linked-issues panel (create/link issue, external state), activity timeline, collision hint ("Alex is replying") via polling, merge/duplicate.
- **Context sidebar** (the most valuable differentiator here): organization (plan, status, subscription), the requester, app instances and their status, recent failed tasks and `TaskEvent`s, domains, with deep links to the existing `admin/organizations/{id}` pages. Any of this is only readable by agents.
- **Settings:** categories, SLA policies and business hours, canned responses, auto-assign rules (round-robin/least-loaded), email settings, attachment limits, retention, integrations.
- **Reports** (extend the admin dashboard): volume, first-response and resolution times, SLA compliance, CSAT, by category/app/org, time spent per org and app.

### Cross-cutting UI rules
- Vuestic components (see the `vuestic-development` skill), Tailwind for layout, Inertia pages with `defineAsyncComponent` for the editor.
- All strings through `__()` / vue-i18n; new `resources/lang/{en,es}/tickets.php` and matching `resources/js/i18n/locales` entries.
- Dark mode, responsive down to phone width, keyboard accessible.

## 11. Additional features you did not list

| Feature | Why | Phase |
|---|---|---|
| Internal notes and @mentions | Agents need private discussion without leaking it | 1 |
| Ticket context (org, app instance, server state, recent failed tasks) | Biggest time saver for hosting-style support | 1 |
| Activity timeline / audit log (`ticket_events`) | Accountability; also feeds integrations | 1 |
| Categories, tags, priority and impact | Routing and triage | 1 |
| Unread tracking and watchers/CC | Teams share one ticket | 3 |
| Email threading + **inbound reply by email** | Most users reply from their inbox. Reply parsing strips quoted text and signatures; verify the sender; plus-address token; Mailgun/Postmark/SES inbound webhook or IMAP poll as drivers | 5 |
| SLA policies, business hours, breach notifications | Core expectation for paid support | 5 |
| Canned responses / macros | Agent efficiency | 5 |
| Auto-assign, auto-close, pending reminders | Hygiene without manual work | 5 |
| Merge/duplicate and ticket links | Outage causes many identical tickets | 5 |
| CSAT after resolve | Quality signal for reports | 5 |
| Reports | Operations | 5 |
| System-generated tickets (failed provisioning task, security scan finding, backup failure, uptime alert) with `source=system` | Proactive support; hooks into the existing `Task`, `SecurityFinding`, and uptime monitor | later |
| REST API for tickets using the org `api_token` | Programmatic ticket creation and integrations | later |
| Incident banner (link tickets to an `Announcement`) | Deflection during outages | later |
| GDPR: export, redact message, anonymize on org deletion, retention policy | Tickets contain personal data and pasted secrets | 1 (redact, soft delete) / 5 (retention) |
| Spam/abuse controls: rate limits, per-org ticket caps, email domain checks | Public inbound is a spam vector | 1 and 5 |
| Search (DB fulltext first, behind an interface to swap in Scout/Meilisearch) | List/inbox usability | 1 |

## 12. Security checklist

- Tenant isolation via scoped queries plus policies, with a test per route (§4).
- Server-side sanitization only; CSP unchanged (do not route tickets through `laravel-filemanager`'s relaxed policy).
- Private attachment disk, signed/authorized downloads, MIME sniffing defence, optional AV scanning.
- Integration secrets encrypted at rest, masked in UI and logs, redacted in delivery logs; SSRF guard on outbound URLs; inbound webhooks verified by signature and replay-limited by timestamp.
- Rate limits on create, reply, upload, and the poll endpoints.
- No internal notes in any requester-facing surface: UI, poll, email, notification, webhook (unless explicitly shared).
- Audit trail for agent actions, including redactions and integration changes.
- Mass-assignment: `$fillable` on all models; form requests for every write.

## 13. Delivery phases

Each phase is a separate PR (or short stack) that ships behind the `tickets` flag, with Pest tests and updated translations.

**Phase 0: decisions and foundations (1–2 days).** Resolve the open questions below, add the `tickets` toggle, `tickets` private disk, `ticket` purifier profile, enums, and `TicketPolicy`/`manage-tickets` gate skeletons.

**Phase 1: core tickets (MVP, replaces the email-only modal).**
Migrations, models, `TicketService`/`TicketWorkflow`, number generator. Org list/create/detail/reply/close/reopen/edit-own-message. Admin inbox/detail/reply/internal-notes/assign/status/priority/category/tags. `TicketEditor.vue` + sanitization. Context sidebar. `ticket_events`. Basic mail + panel notifications (create, reply, status). Help-desk modal now creates a real ticket. Legacy email path kept when the flag is off.
*Exit:* an org user and an admin can complete a full conversation; isolation and XSS tests pass.

**Phase 2: attachments.** Private disk, upload endpoint, binding on create/reply, inline pasted images, download authorization, limits, orphan sweep, thumbnails, optional scanner interface.

**Phase 3: polling and notification depth.** Poll endpoints and `useTicketPolling`, unread tracking, nav badge, watchers/CC, notification preferences, coalescing, email threading headers, panel deep links.

**Phase 4: integrations framework.** Registry, driver contract, schema-driven admin UI, delivery pipeline (queue, retry, circuit breaker, log, redeliver), SSRF guard, generic webhook, then GitHub and GitLab drivers, then Jira and Linear. Time entries and driver time logging.

**Phase 5: operations.** SLA and business hours, canned responses, auto-assign/auto-close/reminders, merge, CSAT, reports, inbound email, inbound tracker webhooks (two-way sync), retention and export.

**Later:** API, system-generated tickets, incident banner, WebSocket push (Reverb), more tracker/time drivers.

## 14. Testing strategy

- **Pest feature tests:** every route for requester/other-org/agent/admin/guest (copy the structure of `tests/Feature/AnnouncementsTest.php` and `tests/Feature/Admin/AdminAccessTest.php`), workflow transitions, notifications fan-out (`Notification::fake()`), visibility of internal notes, attachment authorization.
- **Unit tests:** sanitizer corpus, `TicketWorkflow`, SLA clock/business hours, payload→Markdown conversion, signature generation/verification, SSRF guard (private IPs, redirects, DNS rebinding).
- **Integration tests per driver** with `Http::fake()` recorded fixtures; a shared driver contract test every driver must pass (so third-party modules can reuse it).
- **Browser tests** (`tests/Browser`): create ticket with an attachment, reply, see polled reply appear, admin reply, edit.
- **Static analysis and formatting:** phpstan (larastan, already configured), Pint, `vue-tsc`, ESLint.

## 15. Open questions (need your call before Phase 1)

1. **Visibility:** should every member of an org see all of that org's tickets, or only the requester plus org owners/billing managers (my default)?
2. **Agent permission:** is a new `manage-tickets` permission desirable, or is control-panel admin enough for now?
3. **Edit window:** how long may a requester edit a message (my default: until a staff reply, max 15 minutes), and may they edit the subject?
4. **Priority:** requester picks "impact" only and staff set priority (my default), or can customers choose priority?
5. **Inbound email:** is it required for v1, or is outbound email + in-app replies enough (my default: phase 5)?
6. **Storage:** local disk or S3-compatible for attachments in production?
7. **First integrations:** is generic webhook + GitHub + GitLab the right first set, or do you need Jira/Linear sooner?
8. **Existing `support_email`:** keep as a CC destination for new tickets, or retire it once the inbox exists?
9. **Suborganizations:** should a parent org see child-org tickets?
10. **Retention:** default retention period for closed tickets and attachments (my default: indefinite until an admin sets one)?
