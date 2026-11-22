# Plan: Self-registration, a safer and smoother flow

Status: proposed. Nothing here is built. It comes from reading the code, not from running the app, so the items marked "confirm first" need a quick check in a browser before work starts.

Goal: a person who is handed an organization's registration link can get an account and reach their apps without help, and an organization's administrators stay in control of who gets in. Today the flow works end to end for a friendly visitor, but it skips checks that every other way of adding a user has, it leaks which emails exist, and it gives administrators almost no say.

## What exists today

An app plan can allow self-registration (`self_registration_enabled`, `AppPlan::selfRegistrationEnabled`). On an app instance, an organization administrator then switches it on (`AppInstance::selfRegistrationEnabled`, which also needs the version to have default user roles). The link is organization-wide: `/public/org/{slug}/register`, shown on each app's edit page. It is live while any app in the organization has it on (`Organization::hasSelfRegistrationEnabled`).

1. **Email** (`Pub\Register::submit`): an IP limit (5 per 10 minutes), an optional captcha (Turnstile or hCaptcha, set in `/admin/settings/registration`), a check that the email is not already a user, then an `OrgUserRegistration` token valid for 1 hour and a verification email.
2. **Pending** page: "check your email", with a link back to step 1.
3. **Verify and complete** (`verify`, `complete`): the link opens a form (username, first and last name, phone). Submitting creates the user with a random password, puts them in the default user roles of every app that has registration on, creates a `NewUserCode`, sends the welcome email, fires `UserCreated`, deletes the registration and redirects to the set-password page.
4. **Set password** (`Pub\ChangePassword`), then the welcome page with links to their apps.

## Findings

Ordered by how much they matter. File references are where the behavior lives today.

### Things that are wrong

| # | Finding | Where | Effect |
|---|---------|-------|--------|
| 1 | **The captcha is probably blocked by the Content Security Policy** (confirm first). The policy allows scripts only from `'self'`, the nonce and Stripe, and frames only from Stripe. The page adds the Turnstile or hCaptcha script without a nonce and the widget needs a frame. | `SecurityHeaders.php:62-76`, `OrgRegister.vue` `mounted` | With a captcha configured, no token is ever produced, so the button stays disabled and nobody can register. |
| 2 | **Plan limits and a deactivated organization are not checked.** Adding a user by hand requires the `add-user` gate (organization not deactivated, standard users not at the plan maximum), re-checked under a lock. Self-registration checks neither. | `Account\Users.php:240-246` vs `Pub\Register::complete` | A public link can take an organization past its paid user count, or add users to a deactivated organization. |
| 3 | **The set-password link never expires.** Users added by an administrator get 72 hours; the self-registered `NewUserCode` has no `expires_at`. | `Register.php:155-159` vs `Account\Users.php:265` | A link that sits in an inbox, or in browser history (the user is redirected to it), works forever until used. |
| 4 | **It reveals which emails have accounts.** An address that is already a user gets an error; any other address gets the "check your email" page. | `Register.php:69-71` | Anyone can test whether a person is a member of an organization. |
| 5 | **The throttle is by IP only and is spent too early.** The hit is counted before validation and the captcha, so typos use up the five tries. Several people on one network share them. Nothing limits how many verification emails one address receives, so rotating IPs can mail-bomb a victim. | `Register.php:44-51` | Real people get locked out; the form can be used to send unwanted mail. |
| 6 | **Completing is not safe against a double submit.** The email is only checked in step 1 and the registration is deleted at the end, so two submits with the same link and different usernames create two accounts with one email. A failure after the user is created (for example the mail server) leaves a half-made user and a link that says "username already exists". There is no lock, unlike the administrator path. | `Register.php:118-176` | Duplicate accounts; stuck registrations that need an administrator. |
| 7 | **An expired or used link loses the person's place.** They are sent to step 1 with a generic error and must retype the address. Expired registration rows are never deleted. | `Register.php:102-106`, `OrgUserRegistration` | Friction for the exact people who were slow, and a table that only grows. |
| 8 | **The captcha secret key is sent to the browser.** The settings page returns it as a prop, and saving with a provider but no secret quietly makes every registration fail. | `RegistrationSettings.php:14-16`, `Register::verifyCaptcha` | Secret exposed to anyone who can open the page (administrators only, but it should not leave the server); a misconfiguration nobody can see. |

### Things that could be better

| # | Finding | Effect |
|---|---------|--------|
| 9 | **The organization's language is ignored before sign-in.** The locale falls back through the signed-in user's organization, but a visitor has none (`HandleInertiaRequests.php:49-52`), although the route knows the organization. The new user is also created without a locale. | The org that set a default language (#231) still shows registrants the server's language. |
| 10 | **Registrants are not told what they are joining.** The form shows only the organization name. The welcome email lists the apps, but the page and the verification email do not. | People hesitate or do not recognize the link. |
| 11 | **Administrators have one switch and no say.** There is no allowed email domain list, no approval step, no notice when someone registers, and no record of how a user joined. The toggle is per app but the link is per organization, so turning it on for a second app silently changes what the existing link grants. | Self-registration is all-or-nothing, so cautious organizations leave it off. |
| 12 | **The admin side does not explain itself.** The toggle is hidden when the plan or the version does not allow it, with no hint which. The link is on each app page; there is no single place that shows "registration is on for these apps". | Support questions; people turn it on and cannot find the link. |
| 13 | **A closed link is a bare 404.** | A registrant cannot tell a typo from a closed door. |
| 14 | **The form asks people to invent a username** with a strict rule (letters and digits, lowercase) and no suggestion. | Avoidable failed submits. |
| 15 | **The pending page has no resend, and the emails hard-code "1 hour" and build URLs by string.** | Minor, but each one is a dead end or a stale message. |
| 16 | **There are no tests** for any of `Pub\Register`, `OrgUserRegistration` or the two notifications. | Every change above would be unguarded. |

## Plan

Each phase ends when its exit criterion holds. Phase 1 is the safety fixes and should land first; phases 2 and 3 can go in either order.

### Phase 0: tests for what exists

Add `tests/Feature/Organization/SelfRegistrationTest.php` (Pest, with the fake account manager the other user tests use) covering today's behavior: disabled link is a 404 (plan off, app off, version without default roles, single-tenant mode); happy path creates the user in the default roles of only the apps that opted in; expired token; captcha pass and fail (`Http::fake`); the IP limit; suborganization users get a `SuborgUser`. Pin down findings 2 to 6 as failing or skipped tests so each fix flips one.

Exit: green suite that describes the current flow, with each finding above represented by a test.

**Status: written, not yet run.** `tests/Feature/Organization/SelfRegistrationTest.php` has the tests for today's flow and a skipped test for each of findings 1 to 6 and 8 (finding 7 is two `todo`s, because it needs pages and a command that do not exist yet). The session it was written in had PHP 8.3 and no way to install 8.4, which the project requires, so nothing has run: expect to fix small mistakes in the first CI run. Role membership of the new user is not covered, because the database account manager does not keep app roles; it needs a test in the LDAP suite.

### Phase 1: close the holes (findings 1 to 8)

1. **Captcha and CSP.** Confirm in a browser. Then allow the configured provider's hosts for scripts, frames and connections on the registration routes only (not globally), and load its script with the page nonce. Reset the widget after any failed submit (tokens are single use) and clear the token on its expiry callback.
2. **One rule for "can this organization add a user".** Move the body of the `add-user` gate into a small service that takes an `Organization` (the gate keeps calling it), and use it in `Register::submit` (friendly early message) and again inside `complete` under the same per-organization lock the administrator path takes. Deactivated organizations behave as closed.
3. **Expiry.** Set the set-password code to 72 hours like the administrator path. When it has expired, show a page that says so and offers a new link, instead of a bare 410.
4. **Same answer for every address.** `submit` always redirects to the pending page. For an address that already has an account, send "you already have an account" with the sign-in and password reset links in place of the verification link. Same timing for both (queue both).
5. **Throttle where it counts.** Count a hit only once the captcha has passed and a mail will actually be sent. Add a per-address limit (for example 3 per hour per organization, answered the same way as success, so it reveals nothing). Keep the IP limit as a second layer. Limit `complete` too.
6. **Make completion atomic.** Under the lock: take the registration (delete it and check one row went), re-check the email and username, check the limit (2), create the user. If anything after creation fails, delete the user and put the registration back so the link still works. Add a unique index on `(organization_id, email)` so a second registration for an address replaces the first at the database level, as `generate` does in code today.
7. **Keep the person's place and clean up.** An expired or used link goes to a page that says so, with the email prefilled and a one-click "send me a new link" (counted by the same throttle). Hash the token at rest. Prune expired rows with a scheduled command in `routes/console.php`.
8. **Captcha settings.** Never send the secret to the browser (show "set" and keep it unless a new one is typed). Require site key and secret whenever a provider is chosen. If a provider is set but the secret is missing at runtime, log it and tell administrators on the settings page instead of failing silently.

Exit: the Phase 0 tests for findings 1 to 8 pass; an organization at its user maximum gets a clear "registration is full" message and no new user; the same input produces the same page for a known and an unknown address.

### Phase 2: a smoother path for the registrant (findings 9, 10, 13, 14, 15)

- Resolve the locale from the route's organization on the registration and set-password pages (organization default, then server default), and store it on the new user.
- Show the apps the registrant will get (name and short description, from `selfRegistrationApps()`) on step 1 and in the verification email.
- A closed link shows a neutral "this organization is not accepting registrations" page rather than a 404. The organization slug is already public in the link, so this reveals nothing new; if that is not acceptable, keep the 404 and drop this item.
- Suggest a username from the name as it is typed (`first.last`, lowercased, digits stripped to fit the rule), editable, and say the rule under the field.
- Pending page: show the address, a resend button with a cooldown, and a "check your spam folder" line. Build every URL with `route()` and read the lifetime from config so the email text always matches the real value (new `config/registration.php` for lifetimes and limits, like `config/email.php`).

Exit: a registrant in a non-default-language organization sees their language from the first page; a typo in the address costs one resend, not a restart.

### Phase 3: control for administrators (findings 11, 12)

- **Allowed email domains** (organization setting, optional list). Enforced in step 1 and again in `complete`. Empty means anyone, as today.
- **Notify administrators** of each new self-registered user (panel notification, plus email if they have it on), and mark the user as self-registered so the users page can show and filter by it.
- **Approval (optional, per organization).** When on, step 2 creates the registration as "waiting" instead of the user; administrators approve or reject from a list, and approval creates the user and sends the welcome mail. Build this last, and only if the first two are not enough. It needs its own state, page and notifications, so it should be its own change.
- **One page for registration** in organization settings: whether it is on, which apps are included, the link with copy (and a QR code for posters), the allowed domains, notifications and approval. The per-app toggle stays; each app shows why it cannot be turned on (plan does not allow it, or the version has no default user roles) instead of hiding the switch. Say plainly on that page that the link grants every enabled app.

Exit: an administrator can restrict registration to their own email domain and learns of each signup without opening the users page.

## Decisions for you

1. **Approval queue**: build it (phase 3, last) or leave it out and rely on allowed domains and notifications? It is the largest item here and the one most likely to matter to cautious organizations.
2. **Closed link**: neutral page or keep the 404 (phase 2)?
3. **Terms**: should registration show and record acceptance of terms or a privacy notice? The profile has terms handling (`ProfileTermsTest`); I did not check whether it applies before an account exists.
4. **Roles**: registrants always get the app's default user roles. Is that enough, or do some apps need a separate, narrower "self-registered" role? That would need a role setting per app version and is not in this plan.

## Not in scope

Social or SSO sign-up, inviting people by email (administrators already do that from the users page), registering new organizations (`RegisterController` is a separate flow), and changing which account manager (LDAP or database) stores the users.
