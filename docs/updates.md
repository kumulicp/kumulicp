# Keeping instances up to date

When a plan, a role, a version or an app's settings change, only the app
instances that depend on what changed should be updated, and only for the parts
that changed. This is built on two things: **update steps** (what can be done to
an instance) and **aspects** (what can be out of date).

## Aspects

An aspect (`App\Updates\Aspects\Aspect`) is a facet of an app instance such as
its `release`, its `settings`, its `roles` or its `sso` setup. It works out a
**fingerprint**: a hash of the definitions it depends on (the version, the
plan, the roles, the instance's own settings).

When a step for an aspect has run, the fingerprint is stored (`update_states`).
The instance is **out of date** for that aspect when what it works out now
differs from what was stored. `UpdateDrift` answers the questions:

| Method | Answers |
|---|---|
| `current($app)` | what every aspect works out now |
| `drift($app)` | the aspects that changed since a step last ran for them |
| `stepsToRun($app)` | the steps that go with those aspects, and nothing else |
| `unknown($app)` | aspects nothing is stored for yet (not flagged) |
| `baseline($app)` | take the unknown aspects as up to date |
| `dismiss($app, $only)` | take aspects as up to date without running anything |

An aspect with nothing stored is *unknown*, not out of date, so instances that
were running before this existed aren't all flagged. Run `php artisan
updates:baseline` to store where every instance stands now.

### What goes in a fingerprint

Only what, when it changes, the step has to run again. Never what the step
writes back itself (ids, quotas, `override.pvc`, `sso`), timestamps or counters:
the instance would be out of date again as soon as it was updated. Cosmetic
fields (a plan's name, description or order) belong outside every fingerprint.

### A version's images

A version carries its images (`app_version_images`, see `docs/version-images.md`).
What each image runs (its role, source, registry, repository, tag, digest, registry login
and where it goes in the chart's values) is part of two fingerprints:

- the **release** aspect of every instance of the version, for the images that are deployed:
  the primary image and the components Kumulicp writes into the chart's values
  (`AppVersionImage::isDeployed()`). An image the chart ships, or a job runs, is only
  recorded and flags nothing;
- the **version's tests** (`VersionFingerprint`), for every image, so changing one means
  testing the version again.

What is only about showing an image or checking it for updates (label, position, update
policy, tag pattern, group) is in neither. The primary image that follows the version's
`name` and settings adds nothing: they are in the fingerprints already. That is also what
keeps the fingerprint of a version whose only image is that one exactly what it was before
images were part of it (`AppVersion::imageInputs()` is empty, and the `images` key is
left out), so adding this flagged nothing.

A version that already had an image of its own when this was added (Nextcloud's imaginary)
would have looked changed though it renders as before, so a migration stamps every stored
fingerprint that was still current under the old calculation with the new one. Anything that
was out of date or stale stays so.

### Which aspect a configuration or feature belongs to

The plan's and the instance's configurations and features are split between the
aspects by `App\Updates\AspectInputs`, so changing one only puts out of date
what it feeds:

- **Configurations** go to `release` (they end up in the chart's values). A
  configuration that is really applied by another step says so with an `aspect`
  key, in the app profile or in the plan's `additionalConfigs`:
  `'greeting' => ['name' => 'greeting', 'aspect' => 'settings', ...]`. Raising a
  plan's `replicaCount` is a new release, not a new settings job.
- **Features** (whether they are on, and their settings, on the plan and on
  the instance) go to `settings` (the `update_settings` job). A feature that
  ends up in the chart's values sets `public $aspect = 'release'` on its class.
  A feature with an action (the Nextcloud addons, managed through the API) has
  an aspect of its own and is in neither, unless it sets `$aspect`. Prices and
  the order features are shown in are in no aspect.
- The rest of the plan's settings are listed by the aspect they feed
  (`AspectInputs::PLAN_SETTINGS`); **anything not listed is in no fingerprint**:

| Plan setting | Feeds |
|---|---|
| `base.storage`, `standard.storage`, `basic.storage`, `storage.amount` | `release` (the size of the volume) |
| `secrets`, `additionalConfigs` | `release` (`additionalConfigs` also `settings`) |
| web and database server of the plan | `release` |
| single sign-on server of the plan | `sso` |
| prices (`*.prices`, `*.price`, `*.price_id`), `basic.amount`, `storage.amount`, `payment_enabled`, the prices of features | `subscription` |
| `*.max`, `basic.name`, `admin_access`, `self_registration_enabled`, `expires_after`, `trial_for`, `server_type`, `parent_server_id`, domains, displayed features | nothing: they are checked by kumulicp or only matter when the plan is chosen |

A new setting that an app is built from has to be added to the list, or
changing it flags nothing. The `roles` aspect doesn't depend on the plan at all,
only on the features the roles require. The `subscription` aspect is brought up
to date by running the organization's `SubscriptionUpdate`.

## Noticing changes

Edits to the definitions instances depend on are noticed as they are made, and
only the instances they can affect are looked at.

1. `DefinitionObserver` watches `AppPlan`, `AppVersion` and `AppRole` (the list is
   `ImpactResolver::watched()`).
2. `DefinitionChanges::record()` drops edits that only touched cosmetic
   attributes (a plan's name or order, a version's update check, a role's label)
   and edits nothing uses. What is left is stored in `definition_changes`, with
   who made it and what it was called then.
3. `ScanDriftForChange` finds the instances that use the definition (an
   `AppPlan`'s instances, an `AppVersion`'s, every install of an `AppRole`'s app;
   never test organizations) and `ScanInstancesForDrift` looks at them in batches.
4. `UpdateDrift::refresh()` notes on `update_states` what each aspect works out
   now (`current_fingerprint`), when it was first seen to be out of date
   (`drift_detected_at`) and the edit that did it (`definition_change_id`).
   `UpdateState::drifted()` lists what is out of date without working anything
   out; `php artisan updates:scan` lists it.

The cosmetic list in `ImpactResolver` only narrows what is looked at. What is
out of date is always decided by the fingerprints, so a missed edit (a change
made without the models, a pivot like a role's implied roles that has to be
recorded by hand, as `Roles::update` does) is found by the nightly scan
(`ScanUpdateDrift`, 03:30), which looks at every instance.

An edit to a version's image (`VersionImageObserver`) is noted as an edit to its version,
with `images` as what changed. Edits to what is only about showing an image or checking it
for updates are not noted, nor are those to the primary image that follows the version's
settings: they are noted as the version's own. A bulk update of the images (moving them to
another registry login, say) isn't seen, and is found by the nightly scan.

Servers and applications aren't watched: none of the aspects depend on them. An
organization plan (`Plan`) is, for the organizations on it: see *Organizations*.

## Organizations

An organization's subscription has aspects of its own, which `SubscriptionUpdate`
brings up to date (`App\Updates\OrganizationDrift`; stored in
`organization_update_states`, like `update_states`):

| Aspect | What it works out from | What it is for |
|---|---|---|
| `billing` | pricing, plan selection, status, discount and trial of the organization and its apps | the billing provider |
| `access` | what the plans give the users: storage, access types, which apps are active | the users' settings, and the groups of the apps |
| `capabilities` | whether the plan has domains and email, and their limits | what the organization can add |

`billing` and `access` are the two hashes `SubscriptionSnapshot::capture()` has always
made; `OrganizationDrift` stores them where `SubscriptionUpdate` used to keep the snapshot in the
organization's settings. A fourth row, `plans`, only remembers which plan each part of the
subscription was on, to tell admins which it moved to another from.

- **Settling.** When a `SubscriptionUpdate` has settled, it stores the three aspects and
  the plans (`OrganizationDrift::record()`). The next compares with them to tell what
  changed (`previous()`), as before. An organization that was updated before this has its
  snapshot in its settings: that is read as where it stood, and replaced by the first update.
- **Noticing.** `DefinitionObserver` also watches `Plan`. An edit that wasn't only cosmetic
  (a plan's name, description, order, whether it is offered...) looks at the organizations
  on it (`ScanOrganizationsForDrift`, ten at a time: working out a subscription is more than
  looking at an instance), and the nightly scan looks at all of them.
- **Seeing.** *Server → Updates* has a *Subscriptions* card, the organization list and page
  say when a subscription is out of date, and the dashboard and the menu count them.
- **Bringing up to date.** *Update* runs a `SubscriptionUpdate` for the organizations picked
  (or all of them), which tells the billing provider and refreshes the groups only for what
  changed. *Nothing to do* takes what is listed as up to date without running anything, so
  the billing provider isn't told. With automatic updates on, all three are automatic by
  default and are updated on their own (a failing update is tried three times, six hours apart,
  as for instances).

An edit to an app's plan that changes prices puts the instances out of date for their
`subscription` aspect and the organization out of date for `billing`: either way, running the
organization's `SubscriptionUpdate` brings both up to date.

## After an upgrade

A new version can need steps run that nothing a fingerprint covers has changed for: the
groups for a role the new version adds, say. A step says so with `afterUpgrade()`. Before an
upgrade starts, what nothing is stored for yet is taken as it stands (`UpdateDrift::baseline()`);
when it is done, the aspects of those steps are stored as needing to be brought up to date
again (`UpdateDrift::invalidate()`), so they show as out of date and are ticked in the
app's *Update* list. (This replaces the `update.recommended` setting; what it still holds on
an app is ignored.)

## Seeing what is out of date

`App\Updates\PendingUpdates` reads what the scans stored (it works nothing out)
and is behind everything admins see:

- **Server → Updates** (`/admin/server/updates`): how many app instances and
  organizations are out of date and for what, grouped by the edit that did it
  (what was found without an edit being seen is a group of its own), and the
  instances with the reason for each. A group or an aspect narrows the list
  (`?change=<id|none>&aspect=<key>`). *Check again* looks at every instance now.
- **Nothing to do** takes what is listed as up to date without running anything,
  for a change that needs nothing done to those instances. It only ever takes what
  was picked, and the admin and the time are kept on `update_states`
  (`dismissed_by_id`, `dismissed_at`) until a step brings it up to date or it goes
  out of date again.
- **Dashboard**, **organization list and page** and **the sidebar** (a count on
  Server and Updates, from the `pending_updates_count` shared prop, which a menu
  item asks for with its `badge` key) show it where admins already look.
- **An app's page** says what it is out of date for and why. In its *Update*
  list the steps for what changed are ticked and marked, so updating it does what
  is needed and nothing else (`UpdateSteps::options()`).
- An admin who edits a plan, version or role is told how many instances use it
  and are being checked.

## Bringing things up to date

An instance is updated for what is out of date for it, and nothing else: the
steps that go with the aspects that changed (`UpdateDrift::stepsToRun()`), run
as an `ApplicationUpdate`. `Reconcile` plans and starts that for one instance
(never one that isn't `active`: it is being updated, upgraded or removed) and
previews it for many.

**Many at once.** *Update these* and *Update the selected* on the Updates page
(and `php artisan updates:apply`, which can `--dry-run`) start a `MassReconcile`
task. It updates a few instances first, picked from as many different apps as
there are (`--canary`, one by default), then the rest in batches (`--batch`, ten).
It starts a wave, waits for its `ApplicationUpdate` tasks, then starts the next. If
one of them fails the rollout is blocked, so nothing more is started until an
admin has retried or removed it. What is updated is worked out when its wave
comes, so what an earlier wave or an admin did in the meantime isn't done twice.
Only what was picked is updated, not everything else an instance is out of
date for. An optional window (`start_time` to `end_time`, which may cross midnight)
is when a wave may start; one that is under way isn't stopped at its end.

Before it starts, the page says what would run and for how many, and warns when a
step is *disruptive* (`UpdateStep::disruptive()`): rolling out a release, which
restarts the app, or setting up single sign-on.

**On their own.** Off to begin with. When an admin turns on *Update automatically*
(Updates page), what the scans find out of date is updated without anyone starting
it, for the aspects set to automatic:

- An aspect is automatic unless a step that brings it up to date is disruptive, or
  no step is known for it (an app's own aspects, features). An admin can change any
  of them (`UpdatePolicy`).
- Only the steps for the automatic aspects run, and never a disruptive one. A step
  that brings up to date more than one aspect only runs if all of those that are
  out of date are automatic.
- An update that doesn't get an aspect up to date is tried again after six hours,
  up to three times in all, then left for an admin (`update_states.apply_attempts`).
  It starts over once the aspect has been brought up to date.

## Adding one

A step names the aspects it brings up to date:

```php
class MyStep extends UpdateStep
{
    public function aspects(): array
    {
        return ['my-aspect'];
    }
}
```

An app adds its own aspect and step in its profile:

```php
$profile->addAspect(new MyAspect)->addUpdateStep(new MyStep);
```

A step that names no aspects is never flagged; it only runs when an admin asks
for it.

## Rules

- The fingerprints are worked out when the step's task is created and stored when
  it is done. A change made while it runs leaves the instance out of date.
- A step that fails stores nothing.
