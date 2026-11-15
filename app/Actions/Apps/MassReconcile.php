<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Organization;
use App\Task;
use App\Updates\Reconcile;

/**
 * Brings many app instances up to date for what is out of date, a few at a time:
 * a few first (picked from as many different apps as there are), then the rest in
 * batches, each started once the one before it has finished.
 *
 * Each instance is updated by an ApplicationUpdate task of its own, and this task
 * waits for the ones of a wave. If one of them fails this task is blocked, so no
 * more is started until an admin has retried it or taken it away, and the rest
 * are left as they were.
 *
 * An optional window (a start and an end time, which may cross midnight) is when
 * a wave may be started; one that is under way isn't stopped at its end.
 */
class MassReconcile extends NativeAction
{
    public $slug = 'mass_reconcile';

    public $background = true;

    /**
     * @param  array<int, int>  $instance_ids
     * @param  array<int, string>|null  $aspects  Only what is out of date for these
     */
    public function __construct(
        public array $instance_ids,
        public ?array $aspects = null,
        int $canary = 1,
        int $batch = 10,
        ?string $start_time = null,
        ?string $end_time = null,
    ) {
        $this->organization = Organization::where('type', 'superaccount')->first();

        // The timestamp keeps one rollout from being taken for the one before it
        $this->setCustomValues(array_filter([
            'requested_at' => now()->toIso8601String(),
            'canary' => $canary,
            'batch' => $batch,
            'start_time' => $start_time,
            'end_time' => $end_time,
        ], fn ($value) => $value !== null));

        $this->description = __('actions.reconciling', ['count' => count($instance_ids)]);
    }

    // The lists can be long: they are kept on the task, not looked for among the others to tell tasks apart
    public function onCreate(Task $task): void
    {
        $task->custom_values = array_merge($task->customValues(), [
            'instance_ids' => array_values($this->instance_ids),
            'aspects' => $this->aspects,
        ]);
        $task->save();
    }

    public static function handle(Task $task): ActionResult
    {
        return self::advance($task);
    }

    // The wave that was waited for has finished
    public static function poll(Task $task): ActionResult
    {
        return self::advance($task);
    }

    public static function retry(Task $task): ?Action
    {
        return new self(
            (array) $task->getValue('instance_ids'),
            $task->getValue('aspects'),
            (int) $task->getValue('canary'),
            (int) $task->getValue('batch'),
            $task->getValue('start_time'),
            $task->getValue('end_time'),
        );
    }

    /** Starts the next wave that has anything to update, or is done when there is none */
    private static function advance(Task $task): ActionResult
    {
        if (! self::inWindow($task)) {
            return ActionResult::wait();
        }

        $reconcile = app(Reconcile::class);
        $values = [];

        $waves = $task->getValue('waves');
        if (! is_array($waves)) {
            $waves = $reconcile->waves(
                AppInstance::whereIn('id', (array) $task->getValue('instance_ids'))->get(),
                (int) $task->getValue('canary'),
                (int) $task->getValue('batch'),
            );
            $values['waves'] = $waves;
        }

        $wave = $task->getValue('wave') === null ? 0 : (int) $task->getValue('wave') + 1;
        $updated = (int) $task->getValue('updated');
        $skipped = (int) $task->getValue('skipped');

        for (; $wave < count($waves); $wave++) {
            $started = [];

            foreach (AppInstance::whereIn('id', $waves[$wave])->get() as $app_instance) {
                // The update is worked out now, not when the rollout was asked for: what
                // an earlier wave did, or an admin in the meantime, isn't done twice
                $update = $reconcile->start($app_instance, $task->getValue('aspects'), $task);

                if ($update) {
                    $started[] = $update;
                } else {
                    $skipped++;
                }
            }

            if ($started !== []) {
                return ActionResult::wait([...$values, 'wave' => $wave, 'updated' => $updated + count($started), 'skipped' => $skipped])
                    ->waitingFor(...$started);
            }
        }

        return ActionResult::done([...$values, 'wave' => $wave, 'updated' => $updated, 'skipped' => $skipped]);
    }

    private static function inWindow(Task $task): bool
    {
        $start = $task->getValue('start_time');
        $end = $task->getValue('end_time');

        if (! $start || ! $end) {
            return true;
        }

        $now = now()->format('H:i');

        // A window that crosses midnight (22:00 to 04:00) is the time outside the other way round
        return $start <= $end
            ? $now >= $start && $now <= $end
            : $now >= $start || $now <= $end;
    }
}
