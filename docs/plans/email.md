# Plan: Email ready for production (Stalwart, optionally on top of LDAP)

Status: in progress. Phase 1 (LDAP hardening) is written and waiting on a run against a live directory; the settings foundation of Phase 2 (2a) is merged.

Goal: email that is ready for production, on a Stalwart mail server that is managed by a separate plugin (not in this repository). Stalwart must be able to use LDAP as its authentication backend **and** run without LDAP, so everything email-related has to layer on LDAP optionally. Setting KumuliCP up should not need custom LDAP attributes unless an admin wants a feature that cannot work without one.

## How work is kept inside this plan

Every change names the item below it belongs to, and a slice is only finished when that item's exit criterion is met. Work that does not map to an item is added here first, then built. The control panel's database is the source of truth for email data; LDAP and Stalwart are projections of it.

## Implementation status

**Done** (branch `claude/lucid-gauss-rsgui3`, commit `573795d`) - all of it is Phase 2, item 2a:
- LDAP settings page: an **aliases** attribute and a **recovery email** attribute (`ldap_aliases`, `ldap_recovery_email`). Each must be a valid attribute name and must not be used by any other mapping (defaults included, case-insensitive); the two cannot share one either.
- `LdapEmailAttributes`: aliases are always available without LDAP; with LDAP they are only available once an attribute is set. Recovery email syncs to LDAP only with LDAP and an attribute.
- `user_recovery_emails` table (keyed by username, because an LDAP user has no DB row until first login), `RecoveryEmailService` (DB first, then mirrored to LDAP; an LDAP failure never undoes the save), and `sync()`.
- `/admin/email` page with the alias status, the recovery email status and a **Sync to LDAP** button that makes the directory match the database and lists what was updated, missing, failed and orphaned (orphans are reported, never deleted).
- Deleting a user removes their recovery email (both account manager drivers).
- English and Spanish strings, feature and unit tests (an in-memory directory fake stands in for LDAP).

**Not verified yet** (the environment these were written in could not install Composer dependencies, so no PHP test has been run)
- Every test added for 2a and Phase 1 is unrun. `php -l`, `eslint` and `vue-tsc` pass; `vite build` could not run.
- Phase 1 tests that need a live directory are in `tests/Feature/AccountManager/` (`LdapEmailInterfaceTest`, plus the 2a `RecoveryEmailCleanupTest`) and only run in the `AccountManagerLdap` suite. The rest run in the normal suites against in-memory fakes.
- The LDAP calls were checked by reading LdapRecord 3.8.6's source, not by running them: `setAttribute($attr, [])` followed by `save()` removes the attribute, `addAttributeValue()` skips a value already there, and `whereEndsWith`, `whereHas` and `exists()` exist.

**Differences from the first version of this plan**
- Built before Phase 1. It is independent of it, but the cheap Phase 1 fixes should go next.
- The recovery email is stored in the database and *optionally* mirrored to an LDAP attribute. It was first planned as database-only.
- Aliases with LDAP live in an attribute the admin adds and maps, not as extra `mail` values on a mailbox entry. This also settles the open question about where aliases go.
- The sync page is at `/admin/email`: review dropped the `/admin/org` prefix that was first asked for.

## Decisions

| Decision | Choice |
|---|---|
| Source of truth | The control panel database. LDAP and Stalwart are written from it; a sync button repairs drift. |
| Identity address (`mail`) | The user's org mailbox primary address once they have one, so SSO and apps show the work address. Otherwise their personal address. Always single-valued. |
| Personal email | Becomes the **recovery email** when a mailbox is created. Used for password reset only. |
| Recovery email storage | Database, optionally mirrored to an LDAP attribute. No standard LDAP attribute fits, so none is assumed. |
| Aliases with LDAP | Need an attribute the admin adds and maps. Blank means aliases are off. |
| Aliases without LDAP | Always on; Stalwart is managed directly. |
| LDAP without extras | Still works with no custom attributes: no aliases, recovery email in the database only. |
| SSO matching | On the identity `mail` only. |

## Phases

### Phase 1 - LDAP hardening (written, not yet run against a directory)

Independent of Stalwart. Each item has a test; the ones that need a directory are marked (LDAP).

- [x] `config/ldap.php`: default port `387` is now `389`. Test: `LdapPasswordHashTest`.
- [x] Mailbox address uniqueness. The old rule looked up `cn=<local part>` and never matched. `MailAddressAvailable` checks the full address (local part plus the chosen domain, lowercased) against a `MailAddressRegistry`. The LDAP registry searches the whole directory for the address in `mail` or `orgMail`, so it holds across organizations and covers user-linked mailboxes. `LdapEmailNotExists` is gone. Test: `MailAddressRulesTest`.
- [x] Local part: `EmailLocalPart` allows lowercase letters and digits with single `.`, `-` or `_` between them, up to 64 characters. `+` is deliberately **not** allowed: mail servers read `name+tag` as a tag on `name`, so such a mailbox could never be reached. Test: `MailAddressRulesTest`.
- [x] Quota: `LdapEmailInterface::quota()` writes `5G` everywhere (add, update, user mailboxes) and leaves the attribute out when the plan sets none. Tests: `LdapMailQuotaTest`, `LdapEmailInterfaceTest` (LDAP).
- [x] `Accounts::destroy` no longer connects before checking the domain; it also refuses anything that is not `name@domain`. The same unchecked `explode()` in `update` is fixed, and `update` no longer fails when no password is sent. Test: `EmailAccountsControllerTest`.
- [x] Hashing: one `PasswordHash::make()` (salted SHA-512 crypt, `{CRYPT}$6$...`) for users, mailboxes, the org admin and the directory reader account. The hardcoded salt is gone. Hashes already in the directory are left as they are and verify as before. Tests: `LdapPasswordHashTest`, `LdapEmailInterfaceTest` (LDAP).
- [x] `emailList` matches `*@domain` exactly (`a.com` no longer matches `mya.com`). Test: `LdapEmailInterfaceTest` (LDAP).
- [x] `deleteUserEmail` removes an address case-insensitively and, once the user has none left, also removes the quota, home and storage attributes and the mail object class. Test: `LdapEmailInterfaceTest` (LDAP).
- [x] Forwarders: the LDAP driver now answers `supportsForwarders(): false` and its stubs return empty lists instead of `null`. The forwarders pages redirect to the email accounts page with a message instead of failing. The method is **not** on `EmailContract`, so the Stalwart plugin is not forced to add it (a driver that does not say is treated as supporting forwarding). Whether to build forwarding on Stalwart or drop it stays an open question. Test: `EmailAccountsControllerTest`.
- [x] `email_server_customer_id`, `email_server_domain_id` and `org_servers.server_customer_id` are now strings (numbers carry over; a rollback clears ids that are not numbers). Test: `MakeMailServerIdsStringsTest`.
- [x] `attribute($key, 'array')` on the LDAP user returned the wrong thing (it mapped the key twice). The user edit page's "domains still available" filter matched by substring (`strpos`) and is now `AddressDomains::hasAddressAt()`. `UserView.vue` linked to an undefined `org_email`. Tests: `LdapUserAttributeTest`, `AddressDomainsTest`, `LdapEmailInterfaceTest` (LDAP).

Found along the way, and fixed: `createUserEmail` and `deleteUserEmail` could never have worked on LDAP. They were typed `UserInterface`, which nothing implements, so PHP threw a `TypeError` for every call, and they called `account()`, which the LDAP user does not have. They now take the shared `UserManager` and use `organization()`. Test: `LdapEmailInterfaceTest` (LDAP).

Found, not fixed (belongs to later items):
- The address registry is bound to LDAP for every driver. With the `db` driver and a standalone Stalwart the rule would query a directory that is not there; 2c swaps the binding to the mailbox tables.
- `ProfileEdit.vue` has a branch for `profile.org_emails` that the controller never fills, so it never shows. Remove or replace it in 2d.
- `Forwarders::store` still calls `addEmail()` with an array where the contract expects a password (2f).

Exit: the LDAP email suite passes against a live directory in CI (not yet checked).

### Phase 2 - Identity and mailbox model

- [x] **2a. Attribute settings, recovery storage and sync page** (done, see above).
- [ ] **2b. Wire the recovery email.** Set it when a user is created (the invite address) and let users change it. Require a confirm link before a new recovery address is trusted, notify the old one on change, and add a short cooldown on reset. Password reset and "forgot password" send to the recovery address, with a generic response. Reject a recovery address on a domain this server hosts. Adds a verified column to `user_recovery_emails`.
- [ ] **2c. Mailboxes and addresses.** `mailboxes` (organization, domain, local part, optional user, display name, status) and `mailbox_addresses` (address globally unique and case-insensitive, `is_primary`). Backfill from LDAP with an idempotent artisan command that has `--dry-run` and reports collisions. Count limits from this table, not from a live mail-server query on every page load. Back `MailAddressRegistry` with this table (by driver) so uniqueness no longer needs a directory. Also fixes `countEmail()` ignoring user-linked mailboxes.
- [ ] **2d. Identity swap.** Creating a mailbox for a user moves their current `mail` to the recovery email and sets `mail` to the mailbox address; deleting it restores `mail`. Import the user afterwards so `users.email` updates. Opt-in per user with an impact preview. Audit every app profile that matches accounts by email first (WordPress and CiviCRM first).
- [ ] **2e. Aliases.** Store aliases as non-primary rows in `mailbox_addresses`. With LDAP, write them to the mapped aliases attribute and add them to the sync page; without LDAP, push them to Stalwart. Gate the UI on `LdapEmailAttributes::aliasesEnabled()`. Decide the per-plan alias cap (see open questions).
- [ ] **2f. Contract cleanup.** Fix the `EmailContract` drift (the forwarder call passes an array where a password is expected), remove the blocking `checkConnection()` retry loop from request paths, and retire the LDAP `Domain` entries Stalwart does not need.

Exit: the same contract tests pass in `ldap` and `db` modes for add, update, delete, quota, duplicate address, aliases on and off, cross-tenant isolation and limit enforcement.

### Phase 3 - Domains, DNS and deliverability

- Replace `EmailService::checkEmailDNSSettings`: it requires exactly one TXT record, an exact SPF string and `p=reject`, and `dns_get_record` returning `false` throws. Parse SPF, DKIM and MX instead; make the DMARC policy configurable, starting at `quarantine`.
- Add PTR/rDNS, MTA-STS, TLS-RPT and autoconfig/autodiscover SRV records.
- Have Stalwart generate the DKIM key through its API. Keep the `/api/dkim` route as a deprecated no-op (running jobs may still call it).

### Phase 4 - Security and operations

- Tenant isolation in `ldap` mode: constrain lookups to the organization that owns the domain; do not port the old Dovecot filter (`cn=%n` with no domain or organization constraint). Confirm whether Stalwart's per-tenant isolation needs its Enterprise edition.
- Read-only LDAP bind account for Stalwart, provisioned like `ldap:provision-directory-reader`. Stalwart API credentials go through the encrypted server credentials path.
- Outbound rate limits per organization; no open relay by default.
- Soft mailbox delete with a retention window; organization deletion purges Stalwart and LDAP data.
- Health checks (LDAP bind, Stalwart API), a queue summary and certificate expiry on the admin page. Keep the `emails` toggle off until the exit criteria below are met.

### Phase 5 - Cutover from docker-mailserver

Run Stalwart in parallel, IMAP-sync each domain, lower DNS TTLs, switch MX and the DKIM selector, watch bounces, keep docker-mailserver read-only for a rollback window, then remove `DockerMailServer/*`.

## Exit criteria for production

- The shared contract suite passes in both modes (Phase 2).
- A new domain goes from "enable email" to a delivered and received test message in staging.
- Quota, password-change and delete round trips are verified in Stalwart for each mode.
- The Phase 1 checklist is closed and the verification debt above is cleared.

## Open questions

- Existing users: swap `mail` on request only, or let an admin run a bulk swap per organization?
- Orphans on the sync page: keep them report-only, or let DB-wins clear them after a confirmation?
- Alias cap per mailbox: a plan setting, default unlimited and not billed?
- Do user-linked addresses stay available in `db` mode (the `db` account manager has no `org_email` today)? Assumed yes, through `mailboxes.user_id`.
- Forwarders: keep as a feature (built on Stalwart aliases) or drop?
- Which Stalwart version does the plugin target, and does tenancy there need the Enterprise edition?
- Passwords for user-linked mailboxes: authenticate against the user entry (one password) or give each mailbox its own? Either way the identity address stays on the user entry.
