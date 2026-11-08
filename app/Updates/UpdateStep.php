<?php

namespace App\Updates;

use App\Actions\ActionResult;
use App\AppInstance;
use App\Task;

/**
 * One thing an update of an app can do: roll out the release, run a job, sync
 * the groups in LDAP, run a feature's action. An update runs the steps an
 * admin chose, each as a task of its own.
 *
 * Steps are registered globally (UpdateSteps::register()) or for one app
 * (AppProfile::addUpdateStep()), so a plugin can add its own. A step has to be
 * safe to run again.
 */
abstract class UpdateStep
{
    /** Unique among the steps an app can have; what the update is asked for */
    abstract public function key(): string;

    abstract public function label(): string;

    public function description(): string
    {
        return '';
    }

    /** Whether the app has this step at all (the LDAP driver is in use, it has an SSO server...) */
    public function appliesTo(AppInstance $app_instance): bool
    {
        return true;
    }

    /** Ticked to begin with in the list an admin picks from */
    public function checkedByDefault(): bool
    {
        return false;
    }

    /** Recommended once the app has been upgraded: the new version may need it */
    public function afterUpgrade(AppInstance $app_instance): bool
    {
        return false;
    }

    /**
     * The keys of the steps that have to finish before this one starts, when
     * they are part of the same update.
     *
     * @return array<int, string>
     */
    public function after(): array
    {
        return [];
    }

    /**
     * Does the work. done() when it has finished; wait() (optionally
     * ->waitingFor() the tasks it started) when it hasn't, and poll() is
     * asked again.
     */
    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        return ActionResult::done();
    }

    public function poll(Task $task, AppInstance $app_instance): ActionResult
    {
        return ActionResult::done();
    }
}
