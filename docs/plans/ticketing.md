# Plan: Full Ticket Support

Status: implemented (see the status section below); the rest of this document is the original plan.

## Implementation status

Phases 0 to 5 are implemented on branch `claude/ecstatic-euler-ouvfr8`, behind the `tickets` flag (`TOGGLE_TICKETS=true`).

**Done**
- Flag, `tickets` private disk, `ticket` purifier profile, enums, `TicketPolicy` (members: whole org; staff: `admin` gate through `manage()`), `config/tickets.php`.
- Tables: `tickets`, `ticket_messages`, `ticket_message_revisions`, `ticket_events`, `ticket_categories`, `ticket_tags` (+ pivot).
- `TicketService` (create, reply, edit message, subject/impact, status, priority, category, assign, tags, assignee sweep), `TicketWorkflow`, `TicketNotifier`, `TicketHtml`, `TicketStaff`, `TicketTimeline`.
- Customer side at `/support/tickets`: list (defaults to "assigned to me", empty state shows the other counts), create, detail, reply, edit own message (until staff reply, 15 minutes), edit subject/impact (until first staff response), customer assignee picker, close/reopen.
- Staff side at `/admin/support/tickets`: inbox (defaults to "Mine", Unassigned view with count, filters, search, "assign to me"), detail with public reply / internal note, status, priority, category, tags, both assignees, optimistic locking, customer context (org, plan, requester, apps, recent tasks), categories modal.
- `TicketEditor.vue` (TinyMCE, no file browser), in-place message editing, activity timeline, local draft autosave.
- Email + panel notifications (`TicketActivity`), panel notifications now deep-link to the ticket. Removed or deactivated assignees are handled on the user-deleted event and by a daily sweep.
- The help desk modal creates a real ticket when the flag is on; the legacy email path is unchanged when it is off. New tickets are still copied to `support_email` (`TICKETS_COPY_SUPPORT_EMAIL`).
- English and Spanish strings.

**Phase 5 (service levels, automation and reporting), done**
- **SLA policies and business hours** (`/admin/support/settings`). A policy sets a first-response and a resolution target per priority (typed in hours, stored in minutes) and optionally business hours: time zone, weekdays, one daily window and holidays. One policy is the default; a category can use another; an inactive or deleted policy falls back to the default. Due times are computed when a ticket is opened and recomputed from the opening time when its priority or category changes (`TicketSla`, with the pure `BusinessCalendar` doing the working-minutes arithmetic, daylight-saving safe). The resolution clock pauses while the ticket waits on the customer or is on hold and gives the paused working time back when it resumes. States per clock: ok, warning (20% of the target left), breached, paused, met, missed, none.
- **Alerts.** A 5-minute task (`RunTicketAutomation`, no overlap, one server) flags a warning once and a breach once per clock, as staff-only timeline events, and notifies the assignee (or the whole team when unassigned). Customers never see deadlines. The staff inbox has "Deadline missed" and "Deadline close" views and an SLA chip per row; the ticket page has a service-level card.
- **Automation** (also at `/admin/support/settings`): auto-assign new tickets (`round_robin` or `least_loaded`, optionally from a chosen pool; stragglers are picked up by the task), reminder to the customer after N quiet days (once per wait), auto-resolve a ticket the customer never answered, auto-close resolved tickets, and retention (delete closed tickets after N days). Each rule is off at 0. Changes made by a rule have no actor ("the system") and carry `auto` in the event context.
- **Canned responses** (`/admin/support/canned`), shared or personal, with `{{requester.name}}`, `{{org.name}}`, `{{ticket.reference}}` and a few other variables. The template is sanitised when saved; values from people are escaped; unknown variables stay visible. A selector in the staff reply box fills the editor (nothing is sent).
- **Merge.** Staff merge a duplicate into another ticket of the same organization: the messages, time and tags move to the survivor in order, the earliest first response is kept, the other requester follows the surviving ticket, the source is closed with a public note, and both sides are told. A merged ticket is read-only for everyone and cannot be merged again. Deleting the survivor leaves merged tickets in place without the pointer.
- **Customer satisfaction.** Once a ticket is resolved or closed any member can rate it 1-5 with a comment (the latest rating stands); staff see it on the ticket, in the report and in the export; a rating of 1 or 2 alerts support. The resolved email asks for a rating.
- **Reports and export** (`/admin/support/reports`). Volume per day, open/unassigned now, median and average first response and resolution, SLA compliance, satisfaction distribution, time logged (billable and not), and breakdowns by priority, category, support person and organization, for the last N days (up to a year) or a date range; CSV export of the tickets in the period (formula-injection safe, UTF-8 BOM). A subject-access export of everything one person has put into the desk is available per user as JSON.
- **Redaction and deletion.** Staff can remove the content of a message for good (text, edit history, attachments and any copy already queued for an integration; a placeholder and a staff-only event remain) or delete a whole ticket and everything attached to it. Deleting an organization purges its tickets (`DeleteOrganization`).
- **Two-way tracker sync.** GitHub and GitLab integrations get a webhook address (`/webhooks/tickets/{integration}/{token}`) and a generated secret (GitHub: `X-Hub-Signature-256` HMAC; GitLab: `X-Gitlab-Token`). Comments on a linked issue arrive as staff-only system notes; closing or reopening an issue is noted, and, if the integration option "resolve when the issue is closed" is on, resolves or reopens the ticket as the system. Events are de-duplicated (7 days), our own posted comments (marker `<!-- kcp:ticket -->`) are ignored so nothing loops, the status changes caused this way are not echoed back to the tracker, and merged tickets are never changed. The endpoint answers 404 for an unknown, disabled or mis-tokened address, 401 for a bad signature, 413 above 1 MB, and has no session or CSRF token (the secret in the address plus the signature are its credentials).

**Differences from the plan for Phase 5**
- Business hours are a single daily window on chosen weekdays (plus holidays), not multiple windows per day.
- Ticket links beyond merge ("related tickets") and per-organization ticket caps / spam controls are not built.
- Inbound tracker comments are always staff-only notes; there is no option to publish one to the customer.
- Reports are computed in PHP over the period (bounded to a year); a very large installation would want pre-aggregated tables.
- The legacy `support_email` copy and the old `Support@submit` email path are **still kept** behind the flag; retiring them is a separate change once the desk has been used in production.
- Redaction cannot reach into a tracker that already received the text.

**Phase 4 (integrations), done**
- **Driver framework.** `TicketIntegrationDriver` (name, settings schema, capabilities, connection test) with two kinds on top: `TracksIssues` (create issue, comment, set state, log time) and `ReceivesEvents` (webhooks). `TicketIntegrationRegistry` is a real singleton holding class names only (the Octane-safe pattern used for billing drivers); drivers themselves are built fresh per use. A module adds a driver with `app(TicketIntegrationRegistry::class)->register(MyDriver::class)` from its own provider. The admin form is drawn from each driver's schema, so a new driver needs no front-end work. `AbstractTracker` gives tracker drivers the safe issue/comment/time wording.
- **Built-in drivers.** Signed JSON webhook (`X-KCP-Event`, `X-KCP-Delivery`, `X-KCP-Signature: t=…,v1=hmac_sha256("<t>.<raw body>")`; during secret rotation both the new and the previous secret sign for 7 days), GitHub Issues (github.com or Enterprise; time as a comment) and GitLab Issues (gitlab.com or self-managed; native time tracking via `add_spent_time`).
- **What leaves.** The payload is built once when the event happens, with the integration's privacy options applied, and stored with the delivery: subject, public messages, organization name and a link to the ticket always; requester/staff names and emails only if "include names" is on; attachments by name and size only; internal notes never (the payload builder refuses them). Customer text posted to a tracker is made safe first: `@mentions` and lines starting with `/` (GitLab quick actions such as `/close` or `/assign`, which would otherwise run with our token) are defused with an invisible character, outside code blocks. Titles are single-line and bounded.
- **Delivery pipeline.** Each event becomes a `ticket_deliveries` row (unique idempotency key) and a queued `DeliverTicketIntegration` job. `DeliveryRunner` keeps deliveries for the same integration and ticket in order (a comment never overtakes the issue it belongs to), retries temporary trouble (5xx, 429, 408, connection errors) with waits of 30 s / 2 m / 10 m / 30 m / 1 h / 2 h / 4 h and gives up after 8 tries (honouring `Retry-After`, capped at 6 h), does not retry refusals (4xx), and switches an integration off after 10 failures in a row, telling staff by panel and email. Answers are logged redacted and truncated. Failed deliveries can be redelivered from the log. A 10-minute housekeeping task re-queues anything stuck and forgets history after 30 days.
- **SSRF guard.** All outbound traffic goes through `OutboundHttp`: https only, no credentials in the URL, the host must resolve to public addresses only (including IPv4 forms hidden inside IPv6), the connection is pinned to the address that was checked, redirects are never followed, 10 s timeout, answers over 1 MB are rejected by their declared size. Reaching a private address (a self-hosted GitLab inside the cluster) needs an administrator to switch on "allow private network addresses" for that one integration.
- **Triggers.** Webhooks get the events they subscribe to (`ticket.created`, `message.created`, `status.changed`, `assigned`, `time.logged`). Trackers can open an issue automatically for every new ticket or on request from the ticket page; once linked they follow public messages, resolve/reopen (closing and reopening the issue) and logged time. Per-integration filters limit which tickets are sent (type, priority, category). An integration that throws can never break the ticket write.
- **Time entries.** Staff log time on a ticket ("1h 30m", "90", "1.5h", "1:30"), with a note, a date and a billable flag; totals show on the ticket page; entries are staff-only and never reach customers; they are sent on to linked trackers.
- **Admin pages** at `/admin/support/integrations`: list with health and queue counts, schema-driven create/edit, test connection, switch on/off, rotate or reveal the signing secret, delivery log with the exact payload and answer, redeliver. The staff ticket page gained time, linked issues, "open an issue in…" and recent deliveries.
- Secrets are stored encrypted, never sent back to the browser (only "is set"), and only replaced when a new value is typed.

**Differences from the plan for Phase 4**
- Markdown conversion is a small purpose-built class instead of adding `league/html-to-markdown`: it only has to understand what the ticket sanitiser allows, and a new dependency could not be installed and verified in the build sandbox.
- Jira and Linear are not included (later, through the same contract). Inbound webhooks / two-way sync and a "share this note with the tracker" per-message option are Phase 5.
- Removing a time entry is not undone in a tracker that already heard about it.
- The response size limit uses the declared `Content-Length`; a server that streams an endless answer without one is cut off only by the timeout.
- Deliveries need a running queue worker; with the `sync` queue they run inline when the event happens.

**Phase 3 (polling and notification depth), done**
- **Polling without WebSockets.** Open ticket pages ask `GET .../poll?v=<version>&after=<last message>` every 10 s while visible (60 s when the tab is hidden or the ticket is closed, exponential back-off on errors, immediate check when you return to the tab). Unchanged means `304` with no body. A per-ticket token held in the cache is replaced after every committed write (`TicketVersions`); there are matching tokens per organization and one overall for the staff inbox.
- On a change the page reloads only the props that changed (Inertia partial reload with state preserved), so a half-written reply, an open message edit and unsaved staff edits are never lost. A change that arrives while the tab is hidden is held until you come back, so a background tab never shows (or marks as read) something nobody has looked at. A small toast says how many new replies arrived.
- **Lists and badge.** `GET /support/tickets/summary` returns the unread count and whether the list is stale; the customer list, the staff inbox and the support icon in the top bar poll it (30 s). The icon shows a count of unread tickets you are involved in (opened, assigned to you, or CC'd; for staff assigned to them or watched).
- **Unread tracking.** `ticket_reads` records the newest message each person has seen. Opening a ticket (or a visible refresh of it) marks it read; writing a reply does too. Your own messages never count, customers only count what they can see, internal notes count for staff. Lists show an unread dot and bold row. Reading never moves backwards.
- **Watchers (CC).** Any member can CC colleagues from their own organization; staff can CC members and other staff. A customer editing the list never touches staff watchers. Watchers get the same notifications as the people on their side (customers never get internal notes). The change is recorded in the staff-only timeline.
- **Mute per ticket**, for customers and staff. Muting silences everything except being assigned something.
- **Notification preferences** per person: email and in-panel switches for each kind of event (staff also get internal notes), saved on the user and applied at the moment of sending. Available from a "Notification settings" button on both lists.
- **Email coalescing.** Reply and note emails wait `TICKETS_MAIL_DELAY_SECONDS` (60) and are skipped when the person has read the message meanwhile or the same person has written a newer one in that conversation, so a burst becomes one email. Panel notifications are immediate. (A real queue worker is needed for the delay; with the `sync` queue everything is sent straight away.)
- **Email threading.** The "ticket opened" email carries a root `Message-ID` (`ticket-<id>@<app host>`); every later email carries `In-Reply-To` and `References` pointing at it, and the subject always starts `[KCP-1042]`. Replying by email still does not add a reply.

**Differences from the plan for Phase 3**
- The poll answer says *how many* new messages there are rather than returning them; the page then refreshes through its normal props. One code path renders messages, and edits and redactions show up as well.
- The poll costs one indexed query (to find the ticket and check access) before the cached version is compared, not zero.
- Adding someone as a watcher does not send them a notification.
- The plan's "typing / agent viewing" hints and the `ShouldBroadcast` upgrade path are not built; the event structure was not changed, so Reverb can still be added later.
- Preferences are a modal on the lists rather than a settings page.

**Phase 2 (attachments), done**
- Two-step upload: `POST /support/tickets/uploads` makes a private draft owned by the uploader; sending a message with `attachment_ids` binds it. Drafts survive a failed form and are deleted after 24 hours (`PruneTicketUploads`, hourly). Uploads can be discarded with `DELETE /support/tickets/uploads/{id}`.
- Private `tickets` disk, random object names, original name kept only for display (path and control characters stripped). Nothing is served except through `GET /support/tickets/attachments/{id}`.
- Accepted by an allow-list of extensions **and** the type detected from the file contents (`finfo`), so a renamed program, an HTML page called `logo.png`, or a PDF called `.png` is refused. SVG, HTML, scripts and executables are not on the list.
- Limits: 10 MB per file, 10 files and 25 MB per message, 50 waiting uploads per person (`config/tickets.php`, overridable with `TICKETS_ATTACHMENT_*`).
- Downloads: `nosniff`, a sandboxing CSP of their own (the global CSP middleware no longer overwrites a stricter one), `private` caching; only raster images may display in the browser, everything else downloads (`?download=1` forces it for images too).
- Access follows the message: customers of the ticket's organization open files on public messages; files on internal notes are staff-only; drafts are private to the uploader. Anything not allowed is a 404.
- Screenshots pasted or dropped into the editor are uploaded as attachments and shown inline. The sanitiser keeps an `<img>` only when it points at one of the sender's own attachments for that message (checked against real HTMLPurifier output with remote, `data:`, `javascript:` and path-trick sources). A message may be only files or only a picture.
- `AttachmentScanner` contract (`tickets.attachments.scanner`); an infected or rejected file is refused at upload.
- Picker with drag and drop, progress bars and per-file errors on the new-ticket, customer reply and staff reply forms; attachments listed under each message with image previews.

**Left out of Phase 2 on purpose**
- Thumbnails and EXIF stripping (images are shown at their original size, capped by CSS). The `intervention/image` 4 API could not be exercised in the build sandbox; worth adding with a test environment that can run it.
- A ClamAV implementation of the scanner (only the contract and hook).
- A per-organization storage quota, and deleting files when a ticket is purged or a message is redacted (there is no purge or redaction flow yet; that is Phase 5).
- Adding files while editing a sent message (edits keep the message's own images but cannot add new ones).
- One neutral download URL is used instead of a per-ticket path, so the same link in message HTML works for customers and staff.
- Deployment: PHP's `upload_max_filesize`/`post_max_size` and the proxy/ingress body size must be at least the limits above.

**Differences from the plan above**
- The assigned filter is not remembered in `localStorage`; the list always opens on "assigned to me" and the choice lives in the URL. This follows the instruction that only your own tickets show initially.
- "Leave the page with an unsent message" warning is not built; unsent text is kept as a draft in the browser instead.
- Watchers/CC, unread tracking and polling are Phase 3.

**Verification**
- Vue pages compile (`vite build`) and pass ESLint (also after the Phase 5 screens and strings); PHP passes `php -l` and Pint; the sanitiser profile was exercised directly against HTMLPurifier with XSS payloads.
- The Pest/PHPUnit tests in `tests/Feature/Tickets`, `tests/Unit/TicketWorkflowTest.php` and `tests/Unit/AttachmentTypesTest.php` have **not been run**: the sandbox this was written in has PHP 8.3 and the locked dependencies need 8.4. Phase 5 added `tests/Unit/Tickets/BusinessCalendarTest.php` and, in `tests/Feature/Tickets`, `TicketSlaTest`, `TicketAutomationTest`, `TicketMergeRatingTest`, `TicketSettingsTest`, `CannedResponsesTest`, `TicketReportsTest` and `InboundWebhookTest`; they are not run either. Run `vendor/bin/pest tests/Feature/Tickets tests/Unit` before merging.
- What was exercised for real in the sandbox: all migrations up/down on SQLite, the SSRF address guard against 48 public/private/IPv6-wrapped addresses, the Markdown converter and mention/slash-command defusing, the real GitHub/GitLab/webhook drivers against Laravel's fake HTTP client (request shapes, signatures, error classification, redirects, SSRF refusals: 45 checks), the whole delivery pipeline (queueing, privacy options, idempotency, retries and waits, circuit breaker, ordering, issue linking, state and time sync, time entries through the real `TicketService`: 56 checks), the file-type allow-list against genuine and disguised files with the real `finfo`, the sanitiser (including image handling) against real HTMLPurifier, the real unread/coalescing/subscription services and Eloquent models against SQLite (29 checks), and the real Symfony Mime header API for email threading. That run found and fixed a missing `$fillable` on four models (which would have broken opening any ticket and editing a message) before they shipped.
- Phase 5, exercised for real in the same way: 28 calendar checks (working-minute arithmetic, holidays, time zones, daylight saving), and 103 checks of the real services against SQLite and real HTMLPurifier (SLA due times, pause/resume, sweep, automation rules and the runner, merge, rating, redaction, purge, reports, CSV escaping, canned responses, GitHub/GitLab inbound parsing and the inbound handler). That run found one real flaw, fixed: a `status.changed` delivery used the status at queue time instead of the ticket's current one, which could flap a linked issue.

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

**Added after review (section 11):** an "assigned to" field on both sides, internal notes, SLA, canned responses, ticket context (org/app/server state), outbound email threading, CSAT, merge, time entries, reporting, retention and privacy, API, and security hardening.

**Non-goals for v1:** inbound email (replies are made in the portal only), fine-grained permissions, live chat, WebSocket push, a public knowledge base, multi-brand/multi-queue routing, voice/phone.

### Decisions confirmed

| Question | Decision |
|---|---|
| Who sees an org's tickets? | **All members of the org** see all of the org's tickets. No per-org visibility setting. |
| Agent permissions | Keep the existing `admin` gate for now. Fine-grained permissions (`manage-tickets`, category-restricted agents) are a separate later piece of work. Policies call a single `TicketPolicy` method so this is a one-place change later. |
| Inbound email | Not included. Outgoing emails link to the portal ("reply in the control panel"). |
| First integrations | Generic webhook, GitHub, GitLab. Jira, Linear and others come later via the same driver contract. |
| Assignment | Two assignee fields: a **customer-side** assignee (an org member) and a **support-side** assignee (a staff member). Both are visible on both sides (see §3.1). |
| Default list view | Lists open filtered to **tickets assigned to you** (see §3.1 and §10). |
| Message editing | A member may edit their own message until a staff reply, for at most 15 minutes. The subject is editable until the first staff reply. Revisions are kept. |
| Priority | Customers set **impact** only (minor / degraded / outage). Staff set priority. |
| Attachment storage | Local private disk by default; S3-compatible selectable by config. |
| Existing `support_email` | Kept as a copy destination for new tickets during rollout, with a setting to switch it off. **Retired eventually** (cleanup task in Phase 5: remove the setting, `Mail\SupportTicket`, and the legacy `Account\Support@submit` path). |
| Customer assignee leaves the org | Reassign to the requester if they are still an active member. Otherwise leave it unassigned. **Org owners are notified only if the ticket is still open** (not resolved or closed). Resolved/closed tickets are changed silently. |
| Retention | Indefinite until an admin sets a retention period. |

## 3. Data model

All tables are new migrations (`2026_11_*`). `organization_id` is on every row that can be queried from the org side.

```
tickets
  id, number (unique, human: "KCP-1042"), organization_id, requester_id (users.id, who opened it),
  customer_assignee_id (users.id, null; org member responsible on the customer side, defaults to requester),
  support_assignee_id (users.id, null; staff member handling it),
  subject, type (question|bug|feature),
  status (enum, §3.2), priority (low|normal|high|urgent), impact (requester-supplied: minor|degraded|outage),
  category_id, app_instance_id (null), source (web|email|api|system),
  first_response_at, last_activity_at, last_requester_reply_at, last_staff_reply_at,
  resolved_at, closed_at, due_at, sla_first_response_due_at, sla_resolution_due_at,
  merged_into_id (null), csat_score/csat_comment (null), lock_version, deleted_at, timestamps
  indexes: (organization_id, status, last_activity_at), (support_assignee_id, status, last_activity_at),
           (customer_assignee_id, status, last_activity_at), (number)

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

### 3.1 Assignment ("Assigned to" on both sides)

| Field | Who it is | Who can change it | Defaults |
|---|---|---|---|
| **Customer assignee** (`customer_assignee_id`) | The org member responsible for the ticket on the customer's side (the one support should expect answers from) | Any member of the org, and staff. Must be an active user of the ticket's own organization (validated server-side; cross-org ids are rejected) | The requester at creation. If the assignee is later removed or deactivated: reassign to the requester if still active, else null; org owners are notified only while the ticket is still open (see "Decisions confirmed") |
| **Support assignee** (`support_assignee_id`) | The staff member handling the ticket | Staff only (`admin` gate for now). Customers see the name, read-only | Null (goes to the Unassigned view) or set by category default / auto-assign rule later |

- Both appear in the ticket header and in list columns on **both** sides. The customer UI shows staff by display name only (no email or internal details).
- Changes go through `TicketService::assign($ticket, side, user)`, which writes a `ticket_events` row (`type = assigned`, `context.side = customer|support`) and fires `TicketAssigned`.
- Notifications use the assignees: a reply from support goes to the customer assignee, the requester and watchers; a customer reply goes to the support assignee (or the support team when unassigned). Reassigning notifies the new assignee.
- **Default list filter** (both sides): `?assigned=me` is the initial view. It means `customer_assignee_id = me` for org users, and `support_assignee_id = me` for staff. Other filters in the same control: `all`, `unassigned` (support side: no support assignee; customer side: no customer assignee), and "assigned to a specific person". The choice is kept in the URL (so it is shareable and survives reloads) and remembered per user in `localStorage` (guarded by try/catch). The initial default is always "me".
- A "mine" list with nothing in it shows an explicit empty state with the counts of other tickets ("0 assigned to you, 12 open in your organization — view all") so nobody mistakes it for "no tickets". On the support side, the **Unassigned** view carries a count badge so new tickets are not missed.
- Bulk assign (support side) and a quick "assign to me" button on each row.

### 3.2 Status model

`open` → `pending_support` ⇄ `pending_customer` → `on_hold` → `resolved` → `closed`.

- Staff reply → `pending_customer`. Requester reply → `pending_support`. A reply to a `resolved` ticket reopens it.
- `resolved` auto-closes after N days (setting). `pending_customer` gets a reminder, then auto-resolves.
- A `TicketStatus` backed enum (like `TaskStatus`) with `isOpen()`, `allowedTransitions()`, and the transition rules kept in one `TicketWorkflow` service. Controllers never set `status` directly.

## 4. Authorization and tenancy

- **Org side:** `TicketPolicy`. **Every member of an organization can view, create, reply to, and watch all of that organization's tickets.** Only the message author can edit their own message (§6, §10). Whether a parent org sees child-org tickets is left out of v1 (child orgs are separate tenants); revisit with permissions.
- **Admin side:** staff are users who pass the existing `admin` gate. All policy checks for staff go through one `TicketPolicy::manage()` method, so introducing a dedicated `manage-tickets` permission or category-restricted agents later changes one place, not every controller. Fine-grained permissions are explicitly deferred.
- **Isolation tests are mandatory:** route-model binding is scoped by `organization_id` (`Ticket::forOrganization()`), and a Pest test per endpoint asserts that org B gets 404 for org A's ticket, message, and attachment.
- **Internal notes never reach org-side responses.** Org and admin controllers use separate API Resource classes. The org-side query always filters `visibility = public`, and a test asserts no internal text appears in any org payload (including poll responses and notification bodies).
- Roles for v1: org member (including requester, customer assignee, watcher) and staff/admin. Per-agent restrictions come later.

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
app/Jobs/Tickets/*                            integration delivery, sweeps (orphan uploads, auto-close, SLA)
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
| Ticket created | confirmation (mail) to requester and customer assignee | to support assignee, or the support team if unassigned |
| Public staff reply | mail + panel to customer assignee, requester, watchers | none |
| Customer reply (any org member) | none (author excluded) | support assignee (or team) mail + panel; also the customer assignee/requester if they are not the author |
| Internal note | never | support assignee / mentioned agents |
| Status change / resolved | mail + panel (resolved includes "reopen" link and CSAT) | n/a |
| Assignment | new customer assignee is notified when changed by someone else | new support assignee is notified |
| SLA at risk / breached | n/a | assignee + lead |
| Auto-close / pending reminder | mail | n/a |

- Extend `PanelChannel`/`Account\Notifications@index` to carry a `url` so a panel notification deep-links to the ticket.
- Per-user preferences (mail on/off per event type, stored on a `notification_preferences`-style JSON on the user) and per-ticket mute.
- **Coalescing:** replies in rapid succession send one email (queue with a ~1 minute delay and skip if the recipient already read it via `ticket_reads`).
- **Email threading (outbound only):** `Message-ID` / `In-Reply-To` / `References` headers and subject `[KCP-1042] ...` so mail clients group a ticket's notifications into one conversation. Emails contain an excerpt and a button linking to the ticket; **replying to the email does not add a reply** (inbound email is out of scope), and the footer says so.
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
4. **Later, not in the first release:** Jira (issue + worklog API for time), Linear, Gitea/Forgejo, Azure DevOps, Redmine, Plane, ClickUp, Trello, Asana, and time tools such as Clockify/Toggl/Harvest as "time" drivers. All added without core changes through the driver contract.

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
- **List:** opens on **"Assigned to me"** (`?assigned=me`, §3.1); switch to All / Unassigned / a colleague. Status tabs (Open / Waiting on us / Waiting on you / Resolved / All), search, filter by type/app, unread dots. Columns include **Customer assignee** and **Support assignee** (name only).
- **Create:** subject, type, category, related app (dropdown of the org's app instances), impact, rich text, attachments, CC colleagues, and an **Assigned to** picker for the customer assignee (org members, default: me). The support assignee is not selectable by customers. Show relevant active **announcements** and the documentation link before submit (deflection). Detect pasted secrets (password/API-key patterns) and warn.
- **Detail:** header shows **Customer assignee** (editable by any org member) and **Support assignee** (read-only); threaded conversation (public only), status chip, attachments list, reply composer, quote reply, edit own message (until a staff reply or a time window, with "edited" badge and revision history kept), close/reopen, watchers, CSAT prompt on resolve, "new replies" banner driven by polling.
- **Entry points:** sidebar item with unread badge (via `MenuService`), and the existing `?` help-desk icon becomes "New ticket" (modal reused for quick create → redirects to the created ticket).

### Admin side (`resources/js/Pages/Admin/Tickets/`, under `/admin/support`)
- **Inbox:** opens on **Mine** (`support_assignee_id = me`, §3.1). Other saved views: Unassigned (with count badge), All open, Overdue/At risk, Waiting on customer, Closed. Columns include support assignee and customer assignee. Column sort, filters (org, assignee, status, priority, category, tag, app, SLA state), full-text search, bulk actions (assign, status, priority, tag, close, merge).
- **Detail:** conversation with a public-reply / internal-note toggle, canned-response picker with variables (`{{requester.name}}`, `{{org.name}}`), support-assignee and customer-assignee pickers (customer picker limited to that org's members), priority/status/category/tag controls, time-entry panel, linked-issues panel (create/link issue, external state), activity timeline, collision hint ("Alex is replying") via polling, merge/duplicate.
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
| Assigned-to on both sides, default "assigned to me" list | Requested; keeps each person's list focused | 1 |
| Internal notes and @mentions | Agents need private discussion without leaking it | 1 |
| Ticket context (org, app instance, server state, recent failed tasks) | Biggest time saver for hosting-style support | 1 |
| Activity timeline / audit log (`ticket_events`) | Accountability; also feeds integrations | 1 |
| Categories, tags, priority and impact | Routing and triage | 1 |
| Unread tracking and watchers/CC | Teams share one ticket | 3 |
| Outbound email threading | Notifications group into one conversation in mail clients (inbound reply-by-email is out of scope) | 3 |
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

**Phase 0: foundations (1–2 days).** Settle the remaining open questions below, add the `tickets` toggle, `tickets` private disk, `ticket` purifier profile, enums, and the `TicketPolicy` skeleton (members: full org access; staff: `admin` gate via `manage()`).

**Phase 1: core tickets (MVP, replaces the email-only modal).**
Migrations, models, `TicketService`/`TicketWorkflow`, number generator. Org list/create/detail/reply/close/reopen/edit-own-message. Admin inbox/detail/reply/internal-notes/status/priority/category/tags. Customer-assignee and support-assignee fields with the default "assigned to me" list on both sides (§3.1), including `TicketService::assign()` and its event/notification. `TicketEditor.vue` + sanitization. Context sidebar. `ticket_events`. Basic mail + panel notifications (create, reply, status). Help-desk modal now creates a real ticket. Legacy email path kept when the flag is off.
*Exit:* an org user and an admin can complete a full conversation; isolation and XSS tests pass.

**Phase 2: attachments.** Private disk, upload endpoint, binding on create/reply, inline pasted images, download authorization, limits, orphan sweep, thumbnails, optional scanner interface.

**Phase 3: polling and notification depth.** Poll endpoints and `useTicketPolling`, unread tracking, nav badge, watchers/CC, notification preferences, coalescing, email threading headers, panel deep links.

**Phase 4: integrations framework.** Registry, driver contract, schema-driven admin UI, delivery pipeline (queue, retry, circuit breaker, log, redeliver), SSRF guard, generic webhook, then GitHub and GitLab drivers. Time entries and driver time logging.

**Phase 5: operations.** SLA and business hours, canned responses, auto-assign/auto-close/reminders, merge, CSAT, reports, inbound tracker webhooks (two-way sync), retention and export.

**Later:** fine-grained permissions (`manage-tickets`, per-category agents), Jira/Linear and further tracker/time drivers, API, system-generated tickets, incident banner, WebSocket push (Reverb). Inbound email is not planned.

## 14. Testing strategy

- **Pest feature tests:** every route for requester/other-org/agent/admin/guest (copy the structure of `tests/Feature/AnnouncementsTest.php` and `tests/Feature/Admin/AdminAccessTest.php`), workflow transitions, notifications fan-out (`Notification::fake()`), visibility of internal notes, attachment authorization.
- **Unit tests:** sanitizer corpus, `TicketWorkflow`, SLA clock/business hours, payload→Markdown conversion, signature generation/verification, SSRF guard (private IPs, redirects, DNS rebinding).
- **Integration tests per driver** with `Http::fake()` recorded fixtures; a shared driver contract test every driver must pass (so third-party modules can reuse it).
- **Browser tests** (`tests/Browser`): create ticket with an attachment, reply, see polled reply appear, admin reply, edit.
- **Static analysis and formatting:** phpstan (larastan, already configured), Pint, `vue-tsc`, ESLint.

## 15. Open questions

All earlier questions are resolved (see "Decisions confirmed" near the top). Remaining implementation details to settle during Phase 1, none of which block starting:

1. **"Org owners"** for the removed-assignee notification: use the organization's primary contact plus users with organization-admin access (and billing managers if there is no one else). Confirm against `PermissionsManager` when building it.
2. **Removal hook:** the assignee check runs from the user-deleted/deactivated events (`UserDeleted`, `DeletingUser`) via a listener, plus a daily sweep as a safety net.
