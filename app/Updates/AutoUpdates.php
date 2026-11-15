<?php

namespace App\Updates;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Support\Facades\Action;
use App\Task;
use App\UpdateState;

/**
 * Starts updating an instance on its own for what has gone out of date, when an
 * admin has turned that on for it (see UpdatePolicy). Only the steps that bring up
 * to date what is automatic, and never a disruptive one. One that has failed to
 * get it up to date a few times isn't tried again for a while.
 */
class AutoUpdates
{
    /** Tries before it is left for an admin */
    public const MAX_ATTEMPTS = 3;

    /** Hours between tries */
    public const RETRY_AFTER_HOURS = 6;

    public function __construct(
        private readonly UpdatePolicy $policy,
        private readonly UpdateDrift $drift,
        private readonly UpdateSteps $steps,
    ) {}

    /**
     * @param  array<int, string>  $drifted  What the instance is out of date for
     */
    public function run(AppInstance $app_instance, array $drifted): ?Task
    {
        if (! $this->policy->enabled() || $app_instance->status !== 'active') {
            return null;
        }

        $auto = array_values(array_filter($drifted, fn (string $aspect) => $this->policy->isAuto($aspect) && $this->mayTry($app_instance, $aspect)));

        // A step brings up to date all it names: it only runs if all of those are automatic
        $steps = array_values(array_filter(
            $this->drift->stepsToRun($app_instance, $auto),
            function (string $key) use ($app_instance, $drifted, $auto) {
                $step = $this->steps->find($app_instance, $key);

                return $step && ! $step->disruptive() && array_diff(array_intersect($step->aspects(), $drifted), $auto) === [];
            }
        ));

        if ($steps === []) {
            return null;
        }

        $this->drift->markAttempt($app_instance, array_values(array_intersect($drifted, $auto)));

        return Action::execute(new ApplicationUpdate($app_instance, $steps), null, true);
    }

    private function mayTry(AppInstance $app_instance, string $aspect): bool
    {
        $state = UpdateState::where('app_instance_id', $app_instance->id)->where('aspect', $aspect)->first();

        if (! $state || $state->apply_attempts === 0) {
            return (bool) $state;
        }

        return $state->apply_attempts < self::MAX_ATTEMPTS
            && $state->apply_attempted_at !== null
            && $state->apply_attempted_at->lte(now()->subHours(self::RETRY_AFTER_HOURS));
    }
}
