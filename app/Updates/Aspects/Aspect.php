<?php

namespace App\Updates\Aspects;

use App\AppInstance;

/**
 * One facet of what an app instance should look like, which an update step
 * brings up to date: its release, the roles it creates groups for, its single
 * sign-on setup...
 *
 * An aspect works out a fingerprint from the definitions it depends on (the
 * app's version, its plan, its roles, the instance's own settings). The
 * fingerprint is stored when a step for the aspect has run (see UpdateDrift);
 * when what it works out now differs from what was stored, the instance is out
 * of date for that aspect, and only the steps that go with it need to run.
 *
 * Only put things in a fingerprint that, when they change, the step has to run
 * again. Anything the step itself writes back (ids it receives, quotas it
 * calculates), timestamps and counters belong outside it, or the instance
 * would be out of date again as soon as it has been updated.
 *
 * Aspects are registered globally (Aspects::register()) or for one app
 * (AppProfile::addAspect()), so a plugin can add its own.
 */
abstract class Aspect
{
    /** Unique among the aspects an app can have; what steps name in UpdateStep::aspects() */
    abstract public function key(): string;

    abstract public function label(): string;

    /** Whether the app has this aspect at all (it has an SSO server, uses its own resources...) */
    public function appliesTo(AppInstance $app_instance): bool
    {
        return true;
    }

    /** Hash of everything the aspect depends on, as it is now */
    abstract public function fingerprint(AppInstance $app_instance): string;
}
