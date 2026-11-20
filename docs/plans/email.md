# Plan: Email ready for production (Stalwart, optionally on top of LDAP)

Status: in progress. Phase 1 (LDAP hardening) and most of 2b (an organization address without `orgMail`) are built and pass CI, including the tests that run against a live directory; the settings foundation of Phase 2 (2a) is merged. What is left is listed under "Still to do for 2b".

Goal: email that is ready for production, on a Stalwart mail server that is managed by a separate plugin (not in this repository). Stalwart must be able to use LDAP as its authentication backend **and** run without LDAP, so everything email-related has to layer on LDAP optionally. Setting KumuliCP up should not need custom LDAP attributes unless an admin wants a feature that cannot work without one.

## How work is kept inside this plan

Every change names the item below it belongs to, and a slice is only finished when that item's exit criterion is met. Work that does not map to an item is added here first, then built. The control panel's database is the source of truth for email data; LDAP and Stalwart are projections of it.

## Implementation status

**2b, built and passing CI** (branch `claude/lucid-gauss-rsgui3`): giving a user an organization address, without `orgMail`.
- `mailboxes` table and `Mailbox` model (`pending` while offered, `active` once accepted; the previous email and recovery email are kept so it can be undone). `config/email.php` has the undo window (7 days), the recovery takeover delay (24 hours) and the confirmation link lifetime.
- `MailboxService`: `offer`, `plan`, `accept`, `undo`, `remove`. Accepting a different address moves the current email to the recovery email (or keeps a working recovery email, or asks for one when the current email is on a hosted domain); the address the user already has is only recorded. Writes happen in an order that never loses the old address, a failure puts everything back, the old address is told, and `MailboxActivated`, `MailboxDeactivated` and `UserUpdated` are dispatched (a mail server driver that keeps its own accounts listens for the first two).
- Recovery email: usable means set, different from `mail` and not on a hosted domain. Resets and invites go to it (both user models). Changing it needs the password and a confirmation link, a replacement takes 24 hours to take over, and the old address is told. The address a user is created with is kept as their recovery email.
- Profile page: an organization email card (get, review an offer, undo) with the modal described under "Transition flow", a recovery email section, and, with an organization address, a read-only sign-in email.
- Reminder banner (dismissible for the session) for users with an organization address and no usable recovery email, and the count on `/admin/email`.
- `orgMail` is removed: the `ldap_org_email` setting and its field on the LDAP settings page, the `org_email` mapping, the writes in the docker-mailserver driver (a user's own entry becomes the mailbox: the mail object class, quota and storage attributes; `mail` is set by the switch), and the user pages (they read the mailbox record).
- `email:migrate-org-mail` (dry run unless `--apply`) turns old `orgMail` values into mailboxes without changing what anyone signs in with.
- Found and fixed: `isUserAccessType('standard')` compared an enum to a string in both drivers, so no user could ever be given an organization address; and `orgMail` is not in a standard schema (the CI directory rejects it), which is why the old tests failed.

**Domain readiness (the Fastmail case, hole 2), built:** the address a user already has needs no consent, because accepting it changes nothing for them (the plan's "same address" case), so it is hosted automatically. When a domain's email goes live (`AddEmailDomain::complete`), `DomainReadiness::switchOver` gives every standard user whose `mail` is on it a mailbox and activates it (`MailboxService::acceptIfUnchanged`); an offer already made for that address is accepted too. If the mail server refuses, the mailbox stays `pending` and the user can still accept it from their profile. An administrator offering that same address on a live domain from the user page gets the same result. A different address is still only an offer the user accepts, and an offer on a domain still being set up (`activating` or `waiting_dns`) waits: `MailboxService::accept` refuses until the domain is live. What an administrator can do beforehand: the enable email dialog (the report is a lazy `enable_preview` prop, loaded each time the dialog opens) and, while the domain is being set up, the readiness panel both show how many users will be switched over and how many of them have a secondary recovery email, who doesn't, who can't be switched (not a standard user, a name that can't be a mailbox, or a different offer waiting), and whose recovery email is on the domain. A secondary recovery email on the domain itself doesn't count, because it moves with the mail. Cancelling the dialog and coming back later is how an administrator gives people time to add one. The mail history is not copied, and the panel says so. The bulk "offer them the address they already have" button is gone: the switch does it.

**Secondary recovery email:** a user without an organization address now sees and can set their recovery email too, labelled *Secondary Recovery Email* (with an address it is the *Recovery Email*); an administrator sees it on the user page. The address a user was created with is stored as their recovery email, and it equals their login email, so that one is shown as not set. Same rules as before (confirmation link, the password, not a hosted domain, not the login email), and a usable one receives password resets in place of the login email.

**Still to do for 2b:** the per-app audit (2d) and the checklist below. Not exercised by any test: the readiness panel, the enable email dialog and the secondary recovery email field as a person sees them (no browser test opens them; the browser tests did not run in the environment this was built in).

**Done** (branch `claude/lucid-gauss-rsgui3`, commit `573795d`) - all of it is Phase 2, item 2a:
- LDAP settings page: an **aliases** attribute and a **recovery email** attribute (`ldap_aliases`, `ldap_recovery_email`). Each must be a valid attribute name and must not be used by any other mapping (defaults included, case-insensitive); the two cannot share one either.
- `LdapEmailAttributes`: aliases are always available without LDAP; with LDAP they are only available once an attribute is set. Recovery email syncs to LDAP only with LDAP and an attribute.
- `user_recovery_emails` table (keyed by username, because an LDAP user has no DB row until first login), `RecoveryEmailService` (DB first, then mirrored to LDAP; an LDAP failure never undoes the save), and `sync()`.
- `/admin/email` page with the alias status, the recovery email status and a **Sync to LDAP** button that makes the directory match the database and lists what was updated, missing, failed and orphaned (orphans are reported, never deleted).
- Deleting a user removes their recovery email (both account manager drivers).
- English and Spanish strings, feature and unit tests (an in-memory directory fake stands in for LDAP).

**Verified, and what is not**
- CI (phpstan, eslint, the Unit and Feature suites, the `AccountManagerLdap` suite against a real OpenLDAP, the browser tests) passes for everything above, on both PHP runtimes. That includes the directory tests: the docker-mailserver driver, hosting an address a user already has, and switching to a different address and back.
- Not exercised by any test: the new profile card and modal as a person sees it (the browser tests don't open it), and the Spanish text.
- The Stalwart plugin is outside this repository, so nothing here has run against Stalwart. `MailboxActivated` and `MailboxDeactivated` are the hooks for it, and the plugin has to listen for them.

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
| `orgMail` | Removed. A user's org address is their `mail`; there is no second attribute for it. A DB record (`mailboxes`) says that the `mail` is an org mailbox. |
| Org addresses per user | One, until aliases (2e) exist. A second address on another domain becomes an alias later. |
| New org address, same as the user's `mail` | (An address hosted elsewhere moving to us, such as `me@kumulicp.com` leaving Fastmail.) `mail` does not change, so there is no swap, no risk modal and nothing for apps to notice. The mailbox is recorded and the recovery email is the only thing left to ask for. |
| New org address, different from `mail` | The current `mail` becomes the recovery email, unless that is not usable (see "usable" below), in which case a recovery address has to be given first. An existing usable recovery email is never overwritten silently. |
| A usable recovery email | One that exists, differs from `mail`, and is not on a domain this server hosts. |
| Undo | Yes, for 7 days (`EMAIL_UNDO_DAYS`): `mail` and the recovery email go back and the mailbox returns to `pending`. It does not repair accounts an app already made. |
| Moving mail from another provider | Not built (a separate project). The readiness list only warns. |
| Reminder | A dismissible banner, not blocking. |
| Who can switch a user's `mail` | The user, from the modal below. An admin can provision a mailbox for them, but it stays `pending` and `mail` does not change until the user accepts. |
| Mail-server attributes on users | docker-mailserver stays supported, so the Dovecot attributes (`mailQuota`, `mailHomeDirectory`, `mailStorageDirectory`, the mail object class) are still written for a user with an active mailbox. Stalwart does not use them. |

## Which apps a changed `mail` can affect

An account breaks when an app uses the **email as its key**, or links a new login to an existing account **by email**. Then the next login arrives with the new address and the app makes a second, empty account; the old one is orphaned (not deleted). Apps keyed by a stable id (`sub`, `uid`, entry UUID) only show the new address.

Checked in this repository:
- **Authentik** (SSO): the OIDC `sub` is `hashed_user_id`, a stable value that does not depend on the email.
- **Nextcloud, LDAP:** the username is `cn` and the internal id comes from the entry UUID. `mail` is used for display and notifications, and the login filter also accepts `mail`, so the old address stops working as a login name. The username is unaffected.
- **Nextcloud, OIDC:** the user id mapping defaults to `preferred_username` (the `oidc-mapping-user-id` setting). Safe unless an admin set it to `email`.
- **WordPress** and **CiviCRM Standalone:** both can use LDAP and OIDC, but this repository does not set which claim identifies a user; that lives in the app's own plugin or configuration. **Not determinable from here: audit them (2d).**
- **KumuliCP itself:** keyed by `cn` and the entry guid. `users.email` is unique and re-imported at login, SSO matches on `mail`, and the password reset lookup reads `mail`. All three are covered by 2b.

Apps that are known to key on or link by email (general knowledge, not checked against any version here): ERPNext/Frappe (the user id is the email), Bitwarden and Vaultwarden (the email is the account identity), and Grafana, Discourse and Mattermost in their default link-by-email setups. Apps keyed by a stable id, such as GitLab, Gitea, Synapse and Immich, are generally fine. There is no count to give: it depends on how each app is configured, which is why 2d audits every profile before the swap is offered to existing users.

## Transition flow (2b-iv)

For a user who has, say, a personal Gmail address and gets an address hosted by KumuliCP:

1. **Button** on the user's own profile ("Get a <domain> email"), for users who may have one (`standard` access today) and have none yet.
2. **Same address?** If the user's `mail` is already the address they want (it is on one of the organization's email domains), skip to step 6: no `mail` change, no risk text, just a confirmation. Otherwise continue.
3. **Modal.** Pick the domain and the name (checked as they type against the local-part rule and the registry). It says, in plain words:
   - the email they sign in to KumuliCP and their apps with becomes the new address, and the old address stops working as a sign-in;
   - the current address becomes the recovery email, used only for password resets (or, if they already have a usable recovery email, that one is kept and the current address is no longer used);
   - some apps use the email as the account identity. A list of the apps they have, each with a note on how that app identifies them and what to do (from the 2d audit; unknown apps get a cautionary note).
4. **Confirm** with their password and a checkbox.
5. **Writes**, in this order, so the old address is never lost:
   1. create the mailbox as `pending` (the unique address is the lock);
   2. save the old `mail` as the recovery email (DB);
   3. write the directory: `mail`, and for docker-mailserver the mail object class and attributes;
   4. mark the mailbox `active`, re-import the user so `users.email` updates, sync the Authentik LDAP source;
   5. send a notice to the old address.
   If step 3 or 4 fails the mailbox stays `pending` and `mail` is unchanged; the user can retry, and the sync on `/admin/email` makes `mail` match every `active` mailbox (the database wins).
6. **Reminder** (2b-ii) if there is no usable recovery email afterwards.
7. **Removing the mailbox** restores `mail` from the recovery email only when that is usable; in the same-address case `mail` stays as it is.

An admin who wants a user to have an address creates the mailbox as `pending` and the user is prompted to accept at the next sign-in. A user created with an org address from the start has no apps yet, so there is no modal; their invite goes to the personal address they were created with, which becomes the recovery email.

### Holes found in this logic, and how the plan closes them

| # | Hole | Closed by |
|---|---|---|
| 1 | The address is "taken" by the user's own `mail`, so the uniqueness rule would refuse the same-address case. | The registry check ignores the requesting user's own entry, for an exact match only. |
| 2 | A domain moving from another provider (Fastmail) cannot get mailboxes until its email is active, which only happens after its DNS points here, and the DNS switch moves **every** address on the domain at once. So mail would bounce between the switch and the mailboxes existing. | `pending` mailboxes may be created while a domain is still activating. The domain's DNS is only switched after a readiness list: every user whose `mail` is on the domain has a mailbox, and the users whose recovery email is on it are flagged (it would stop being reachable). The mail server registers the domain only when it is active. |
| 3 | `mail` is on a hosted domain but is a different address from the new one (`jane@` becoming `jdoe@`). Demoting it to recovery would put the recovery email on a domain we host. | Not demoted. A recovery address elsewhere has to be given first. The old address can become an alias later (2e). |
| 4 | The user already has a recovery email, and moving `mail` over it would overwrite it. | Never silently. The modal says which is kept; the old `mail` is then no longer used. |
| 5 | An admin can create a user's address today, which would change that user's sign-in and orphan their app accounts without their knowledge. | The user accepts; an admin's mailbox is `pending`. The current admin-side button changes to "offer". |
| 6 | The control panel signs in with the email (`Auth::attempt` on `email`, both drivers), so the swap changes the sign-in and the old address stops working. | Stated in the modal. Existing sessions are unaffected. |
| 7 | Reset links and invites go to `mail` today, and `User` (database) has no override at all. After the swap they would go to the org mailbox, which someone who has forgotten their password may not be able to open. | They go to the recovery email (2b-i). With no usable recovery they fall back to `mail` and the admin sees a warning. |
| 8 | "Recovery missing" is not the only bad case: it is also unusable when equal to `mail` or on a hosted domain. | One definition of "usable" drives the reminder, the admin count and the readiness list. |
| 9 | The profile and admin user forms edit `mail` directly, so a user with an org mailbox could overwrite their identity. | With an active mailbox `mail` is read-only and the same field edits the recovery email. |
| 10 | A failure part-way leaves `mail`, the recovery email and the mailbox disagreeing. | The write order above, and the sync covering `mail` for active mailboxes. |
| 11 | The swap changes the account's identity and its reset address, which makes a hijacked session worth more. | Password confirmation, and a notice to the old address. |
| 12 | There is no single safe order for the user's apps: for some, change the email in the app first; for others, after. Existing app sessions also keep the old email until they sign in again. | Per-app guidance from the audit (2d), declared on each app profile (how it identifies a user). Unknown means a cautionary note. |
| 13 | A user created with an org address from the start would get the invite at a mailbox they cannot open yet. | The invite goes to the personal address (now the recovery email). |
| 14 | docker-mailserver stays, but `pending` must not write the mail object class or `mail`, or Postfix would accept mail for an address that is not live. | Those are written only when the mailbox becomes `active`. |
| 15 | Moving off another provider needs the old mail history copied (IMAP sync) before the DNS switch, and nothing here does that. | Not built. The readiness list says so. Open question below. |
| 16 | Deleting a mailbox for a user whose recovery email is missing or unusable would leave `mail` pointing at an address we no longer host. | `mail` is restored only from a usable recovery email; otherwise it is left and the reminder shows. |

## Phases

### Phase 1 - LDAP hardening (done; CI runs its tests, including against a live directory)

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

Exit: the LDAP email suite passes against a live directory in CI (checked: it does).

### Phase 2 - Identity and mailbox model

- [x] **2a. Attribute settings, recovery storage and sync page** (done, see above).
- [ ] **2b. Recovery email, and org addresses without `orgMail`.** The swap and the removal of `orgMail` go together, because without `orgMail` nothing else records that a user's `mail` is an org address.
  - [x] *2b-i Recovery email.* Created with the user, changed with the password and a confirmation link, a replacement takes over after 24 hours, resets and invites go to it, and it can't be on a hosted domain or equal to `mail`.
  - [x] *2b-ii The reminder.* Banner, admin count, the badge in the users list, and the recovery emails on a domain in its readiness panel.
  - [x] *2b-iii `mailboxes` and removing `orgMail`.* The registry reads the mailbox table and the users' sign-in emails (and still the legacy `orgMail`, until it has been migrated) and ignores the requesting user's own `mail`.
  - [x] *2b-iv The transition flow.* Done: the button, modal, writes, notice to the old address, admin offer, undo. `pending` mailboxes while a domain is still being activated, and the readiness panel before its DNS is switched (hole 2), are done too.
  - [x] *2b-v `email:migrate-org-mail`.* Dry run by default. An address that is the one the user signs in with is hosted and its `orgMail` value cleared; any other is offered and the user accepts it from their profile; a later run clears the value once they have. A user with several values is listed and skipped.
- [ ] **2c. Aliases table and the rest of the mailboxes.** `mailbox_addresses` (address globally unique and case-insensitive, `is_primary`) on top of the 2b `mailboxes`. Backfill standalone mailboxes from LDAP with an idempotent artisan command that has `--dry-run` and reports collisions. Count limits from the tables, not from a live mail-server query on every page load. Back `MailAddressRegistry` by driver so uniqueness no longer needs a directory. Also fixes `countEmail()` ignoring user-linked mailboxes.
  - [x] *2c-i The table.* `mailbox_addresses` (lower-cased, unique, one primary row per mailbox that follows `mailboxes.address`; existing mailboxes are backfilled by the migration). The database registry reads it, so an alias will count as taken. Standalone mailboxes made or deleted on the email accounts page are recorded in `mailboxes` (no `username`), and `countEmail()` now counts the organization's `active` mailboxes from the table: standalone and user-linked, not offers. **Until the backfill below has run, standalone mailboxes made before this are not counted**, so limits are looser than they should be.
  - [x] *2c-ii `email:backfill-mailboxes`* (`--dry-run`, `--organization=`) records the standalone mailboxes that exist on the mail server but not in the table. It only adds rows and is safe to run again. It reports, and does not force, an address a user signs in with, one recorded for another organization, a mail server it could not ask, and recorded mailboxes the server no longer has. **Run it once per install after upgrading** (`--dry-run` first), because counts and limits come from the table now. Until the LDAP registry is no longer asked about mailboxes, it stays in the composite; with the backfill done it only needs to answer for users' `mail`.
- [ ] **2d. Rollout safety for the swap.** Audit WordPress and CiviCRM for the claim that identifies a user (and Nextcloud's `oidc-mapping-user-id` on existing installs). Show an impact preview with the user's apps before the swap. The migration command from 2b only runs on existing accounts after the audit.
- [ ] **2e. Aliases.** Store aliases as non-primary rows in `mailbox_addresses`. With LDAP, write them to the mapped aliases attribute and add them to the sync page; without LDAP, push them to Stalwart. Gate the UI on `LdapEmailAttributes::aliasesEnabled()`. Decide the per-plan alias cap (see open questions).
- [ ] **2f. Contract cleanup.** Fix the `EmailContract` drift (the forwarder call passes an array where a password is expected), remove the blocking `checkConnection()` retry loop from request paths, and retire the LDAP `Domain` entries Stalwart does not need (once docker-mailserver, which still reads them, is gone).

Exit: the same contract tests pass in `ldap` and `db` modes for add, update, delete, quota, duplicate address, aliases on and off, cross-tenant isolation and limit enforcement.

### Phase 3 - Domains, DNS and deliverability

**What is wrong today** (all in `EmailService` and `AddEmailDomain`, checked in the code):
- `checkEmailDNSSettings` returns `true` outside production, so staging and every test pass whatever the DNS says, and "waiting for DNS" can never be exercised.
- In production it needs *exactly one* TXT record at the apex, at `_dmarc` and at the DKIM selector. A domain with any other apex TXT (a Google or Microsoft site verification, a leftover SPF) never passes. It compares the SPF and DMARC strings for equality, `dns_get_record` returning `false` throws, and it uses the server's own resolver, so a cached answer reads as "propagated".
- The records are derived, not stored: `requiredDNSRecords()` builds MX, SPF, DKIM and DMARC from the server on every call, and for a managed domain Namecheap's `setHosts` is told the *whole* record set each time. There is nowhere to keep a change someone made to one of them.
- SPF is `v=spf1 ip4:<server ip> ~all`: no way to add another sender, and it breaks when the server's IP changes. DMARC is `p=reject` with `rua` set to the *administrator's* `error_email` setting for every tenant, so reports go to the wrong person and `reject` is the strictest place to start. The MX value has no priority. Nothing checks PTR, TLS, MTA-STS, TLS-RPT, autoconfig or blocklists.

**3a. Email gets its own tab on the domain page.** `WebDomainsSettings.vue` becomes tabs, with the tab kept in the URL (`?tab=email`) so redirects can land on it:
- *Overview* (what is there now: primary app, redirect, ownership actions), *DNS* (subdomains and records), and **Email**.
- Email holds everything now spread over the page: the status chip and the "being set up" alerts, the enable email action, the readiness panel and its enable preview from 2b, the required records, and the checklist and the edit dialog below. The `enable_email` redirect and the readiness offer flows send people to the Email tab.
- A domain without email shows the Email tab with a single "Enable email" call to action and the preview, as the dialog does today.

**3b. Checks that look at the real DNS, not at what was saved.** A `DnsResolver` seam (a fake in tests, so it works in every environment) replaces `dns_get_record`:
- It asks the domain's **authoritative nameservers directly** (found from the domain's NS records) and **public resolvers** (Cloudflare, Google and Quad9, over DNS-over-HTTPS or a resolver library; the system resolver cannot be chosen or flushed).
- Every check reports three layers, because "I added it" is not "it works": *saved* (a managed domain only: what Namecheap's `getHosts` returns), *served* (every authoritative nameserver answers with it), and *visible* (the public resolvers agree). A managed domain whose nameservers are not the registrar's is called out, because what is saved at the registrar will never be seen. A connected domain only has *served* and *visible*, which is why the scan matters most there.
- A check is `pass`, `warn`, `fail`, `pending` (served but not visible yet: "propagating", with the TTL as an estimate of how long), `unknown` (a lookup timed out; never reported as a failure) or `not applicable`. Each result keeps what was found and what was expected, so the page can show the difference.
- Results are stored (`domain_email_checks`: check, status, found, expected, scanned at) and shown with when they were scanned and a **Rescan** button. While anything is `pending` a queued job rescans with a back-off (1, 2, 5, 15 minutes, then hourly for 48 hours). A domain moves from `waiting_dns` to `active` when every required check has passed on two scans in a row, which replaces `checkEmailDNSSettings`.
- Two other things the scan notices: a Cloudflare-style *proxied* record (orange cloud), which breaks mail and autoconfig hosts, and a domain still pointing at its old provider (leftover MX or SPF), which is the Fastmail case from 2b.

**3c. The checklist.** Required items must pass for the domain to go live; the rest raise the score and are warnings only. Each item says what it is for, what to do, and links to the record that fixes it.

*Required*
| Item | Passes when |
|---|---|
| Nameservers | Every NS answers, and (managed) they are the registrar's. |
| MX | At least one MX, pointing at the mail host, with a priority; no MX left from another provider; the host resolves to an A or AAAA and is not a CNAME. |
| SPF | Exactly one `v=spf1` TXT at the apex; it authorizes the mail server; at most 10 DNS lookups; ends in `~all` or `-all`; no `+all`, `?all` or `ptr`. Other TXT records at the apex are fine. |
| DKIM | The selector record exists, is valid, is at least 2048 bits (1024 is a warning) and its `p=` matches the key the mail server signs with; long keys split into 255-character strings are joined before comparing. |
| DMARC | Exactly one `v=DMARC1` at `_dmarc`, with a policy, and a `rua` mailbox that exists and belongs to the tenant (or an authorized external destination). |
| Autoconfig and autodiscover | The records and hosts from 3d answer, with a valid certificate for the host. |
| SMTP and IMAP reachable | Port 25 accepts STARTTLS with a certificate that matches the MX host; submission (465 or 587) and IMAPS (993) answer with a valid, unexpired certificate. |

*Boosts deliverability (warnings)*
| Item | Passes when |
|---|---|
| Reverse DNS | The sending IP's PTR resolves back to the same IP and matches the server's EHLO name (forward-confirmed). One result for the mail server, shown to administrators (a tenant cannot fix it). |
| DMARC policy | `p=none` warns; `quarantine` is the default; `reject` is a goal once reports are clean. `pct`, subdomain policy and alignment are shown. |
| MTA-STS | `_mta-sts` TXT and a policy that is served over HTTPS, in `testing` first and `enforce` later. |
| TLS-RPT | `_smtp._tls` TXT with a `rua`. |
| Blocklists | The sending IP and the domain are not on the major lists. Spamhaus refuses queries from the big public resolvers, so this asks through our own resolver (or a DQS key). |
| Clean records | No second SPF or DMARC record, no stale DKIM selectors, no wildcard that answers for the mail hosts. |
| TTLs | Low (300 s) while a domain is being moved, and a reminder to raise them after. |
| DNSSEC, DANE (TLSA), BIMI | Shown as advanced and optional: DNSSEC only informs, DANE needs DNSSEC, BIMI needs a DMARC policy of quarantine or reject and a verified logo certificate. |
| Postmaster tools | Not scanned. A note with how to register the domain with Google Postmaster Tools and Microsoft SNDS, and the DMARC reports we collect. |

A score and a "what to fix next" list sit at the top of the Email tab. An optional **send a test message** (to an address the person gives) reads back the `Authentication-Results` of what arrived once there is somewhere that can receive it.

**3d. Autoconfig, autodiscover and the other client records.** Added to the required set (`requiredDNSRecords()` stops being hard-coded; see 3e):
- `autoconfig.<domain>` CNAME to the mail host (Thunderbird and others read `/mail/config-v1.1.xml`), `autodiscover.<domain>` CNAME and `_autodiscover._tcp` SRV on 443 (Outlook), and the RFC 6186 SRVs `_imaps._tcp`, `_submission._tcp` and `_submissions._tcp` for clients that read those.
- Stalwart already serves the autoconfig and autodiscover documents; the plugin has to answer for those hostnames for every tenant domain. The checklist verifies the answer, not just the record.
- MTA-STS (`_mta-sts` TXT and the `mta-sts.<domain>` host) and TLS-RPT (`_smtp._tls`) go in the same place, optional at first.

**3e. Records stored, and one dialog to edit them.** A `domain_email_records` table: the record kind (MX, SPF, DKIM, DMARC, autoconfig, autodiscover, SRV, MTA-STS, TLS-RPT), a `mode` (`auto` or `manual`), the builder's options as JSON and the rendered value. `requiredDNSRecords()` reads it, with today's values as the defaults, so existing domains keep working. A managed domain saves through `UpdateDnsRecords` (the whole set to Namecheap); a connected domain shows exactly what to add and checks it.
- Each record has an **Edit** button that opens a dialog written in plain words, not record syntax:
  - *SPF*: "Also send mail from" with a list of services to add (their `include:` values, with the lookup count shown), and a choice of "soft fail" (`~all`, default) or "reject" (`-all`). The value is shown live under the controls.
  - *DMARC*: policy (none, quarantine, reject; starts at quarantine), percentage, where reports go (a mailbox of the domain, the default), strict or relaxed alignment, and a subdomain policy.
  - *DKIM*: read-only, with the selector and a **Generate a new key** action (the key comes from Stalwart; rotation publishes the new selector before using it).
  - *MX, autoconfig, autodiscover, MTA-STS, TLS-RPT*: switches and a mode where there is a choice; nothing to type.
  - Each ends in "this is what will be published", the same rows as the table, and **Save**.
- **Edit manually** on the same dialog turns the controls into the raw value. It validates with the same parser the checks use, warns that a wrong value can stop mail, and marks the record `manual` so a later change of the mail server's IP or key does not overwrite it. **Reset to recommended** goes back to `auto`.
- Connected domains: each row has the host as the provider wants it (relative and fully qualified), the value, a suggested TTL and a copy button, plus a note when the nameservers show Cloudflare (turn the proxy off for the mail hosts). After "I have added them" the scan from 3b follows the result.

**3f. DKIM from Stalwart.** Have Stalwart generate the DKIM key through its API, and fix `RetrieveDkimKey`'s dependence on the `/api/dkim` callback. Keep the `/api/dkim` route as a deprecated no-op (running jobs may still call it).

**Suggested slices** (each about 500 lines or less, in this order, each leaving the page working): 3a the tab move alone; 3b `DnsResolver`, the check result table and the scan job, replacing `checkEmailDNSSettings` behind the existing statuses; 3c the checklist items in groups (records, then transport, then reputation) with their tab section; 3e the records table, the generated defaults and the edit dialog (SPF and DMARC first); 3d the new records, then MTA-STS and TLS-RPT; 3f DKIM from Stalwart.

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
- A new domain, on a connected and on a managed setup, shows every required item of the Phase 3 checklist as passed from a scan that looked at the real DNS (authoritative and public), and a message from it passes SPF, DKIM and DMARC at a third-party mailbox.

## Open questions

- Phase 3 resolver: DNS-over-HTTPS to a few public resolvers, or a DNS library talking to them and to the authoritative servers directly? (Port 53 outbound may be blocked from the cluster; DoH always works but cannot ask an authoritative server.)
- Where do DMARC aggregate reports go? A mailbox per domain (`dmarc@<domain>`, which needs a mailbox), the administrator's address as today, or a service that parses them? Reports to the wrong person are worse than none.
- Autoconfig and autodiscover need a certificate for `autoconfig.<domain>` and `autodiscover.<domain>` for every tenant domain, and MTA-STS one for `mta-sts.<domain>` plus a web server answering it. Does the plugin issue those per host (HTTP-01 against a CNAME), or is a wildcard on our own hostname used with CNAMEs only?
- Is MTA-STS built in the first slice or left optional until the rest is stable? (It is only useful once it is `enforce`, and a mistake there blocks inbound mail.)
- Blocklist checks: our own resolver, or a Spamhaus DQS key?
- Existing `orgMail` users: run the migration command per organization once the audit (2d) is done, or per user an admin picks? (It can already do either: `--organization`, `--user`.)
- Orphans on the sync page: keep them report-only, or let DB-wins clear them after a confirmation?
- Alias cap per mailbox: a plan setting, default unlimited and not billed?
- Do user-linked addresses stay available in `db` mode? Assumed yes, through the `mailboxes` record (the `db` account manager has never had a second address).
- Forwarders: keep as a feature (built on Stalwart aliases) or drop?
- Which Stalwart version does the plugin target, and does tenancy there need the Enterprise edition?
- Passwords for user-linked mailboxes: authenticate against the user entry (one password) or give each mailbox its own? Either way the identity address stays on the user entry.
