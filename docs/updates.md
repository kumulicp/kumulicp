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
