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

Not watched yet: servers, applications and the organization plans (`Plan`). None of
the aspects depend on them.

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
