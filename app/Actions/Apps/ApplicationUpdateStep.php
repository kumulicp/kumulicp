<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Task;
use App\Updates\UpdateDrift;
use App\Updates\UpdateSteps;

/**
 * One step of an app's update, as a task of its own so it shows in the task
 * list and can be retried on its own.
 */
class ApplicationUpdateStep extends NativeAction
{
    public $slug = 'application_update_step';

    public static bool $long_running = true;

    public function __construct(AppInstance $app_instance, string $step)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $found = app(UpdateSteps::class)->find($app_instance, $step);

        // What the step's aspects work out now, stored once it is done (see UpdateDrift::forStep)
        $this->setCustomValues([
            'step' => $step,
            'aspects' => $found ? app(UpdateDrift::class)->forStep($app_instance, $found) : [],
        ]);

        $label = $found?->label() ?? $step;
        $this->description = __('actions.update_step', ['step' => $label, 'app' => $app_instance->label]);
    }

    public static function handle(Task $task): ActionResult
    {
        $step = app(UpdateSteps::class)->find($task->app_instance, (string) $task->getValue('step'));

        if (! $step) {
            return ActionResult::fail(__('messages.action.update_step_unknown', ['step' => $task->getValue('step')]), 'unknown_step');
        }

        return self::finished($task, $step->handle($task, $task->app_instance));
    }

    public static function poll(Task $task): ActionResult
    {
        $step = app(UpdateSteps::class)->find($task->app_instance, (string) $task->getValue('step'));

        if (! $step) {
            return ActionResult::fail(__('messages.action.update_step_unknown', ['step' => $task->getValue('step')]), 'unknown_step');
        }

        return self::finished($task, $step->poll($task, $task->app_instance));
    }

    public static function retry(Task $task): ?Action
    {
        return new self($task->app_instance, (string) $task->getValue('step'));
    }

    // A step that has run no longer needs to be recommended, and what it brought up to date is remembered
    private static function finished(Task $task, ActionResult $result): ActionResult
    {
        if ($result->outcome === ActionResult::DONE) {
            app(UpdateSteps::class)->forget($task->app_instance, (string) $task->getValue('step'));

            if (is_array($aspects = $task->getValue('aspects')) && $aspects !== []) {
                $drift = app(UpdateDrift::class);
                $drift->record($task->app_instance, $aspects, $task->id);
                // What was stored is what the step started with: if something changed while it ran, it is out of date again
                $drift->refresh($task->app_instance);
            }
        }

        return $result;
    }
}
