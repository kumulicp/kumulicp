<?php

namespace App\Actions\Steps;

use App\Actions\ActionResult;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Task;
use Illuminate\Support\Facades\DB;

/**
 * A step that waits for a person: the task has gone as far as it can until an administrator has done
 * something outside the control panel (restored a volume, say) and says so on the app's page.
 *
 * It waits for as long as it takes. There is no timeout, because nobody can say how long a person
 * needs, and the task can still be cancelled from the Tasks page, which rolls it back. What it is
 * waiting for is on the task (`awaiting`), where the app's page finds it, and its message is the
 * task's, which the Tasks page shows.
 *
 * What the administrator gives when confirming (a form, say) is checked by rules() and kept on the
 * task under `confirmed` for the later steps to use.
 */
abstract class AwaitConfirmation extends Step
{
    /**
     * What the administrator has to do before confirming.
     */
    abstract protected function message(Task $task): string;

    /**
     * Rules for what is given when confirming; nothing, for a step that only needs the go-ahead.
     *
     * @return array<string, mixed>
     */
    public static function rules(Task $task): array
    {
        return [];
    }

    /**
     * What the app's page shows to be filled in with the go-ahead, if anything: a `type` for the page
     * to know which form it is, and what that needs.
     *
     * @return array<string, mixed>|null
     */
    public static function form(Task $task): ?array
    {
        return null;
    }

    public function run(Task $task): ActionResult
    {
        $this->show($task);

        return ActionResult::wait(['awaiting' => ['class' => static::class, 'message' => $this->message($task)]]);
    }

    public function check(Task $task): ActionResult
    {
        if (static::confirmation($task) !== null) {
            return ActionResult::done(['awaiting' => null]);
        }

        $this->show($task);

        return ActionResult::wait();
    }

    // No timeout: see above
    public function timeoutMinutes(): ?int
    {
        return null;
    }

    private function show(Task $task): void
    {
        $message = $this->message($task);

        if ($task->error_message !== $message) {
            $task->error_message = $message;
            $task->save();
        }
    }

    /**
     * What was given when this step was confirmed, or null if it has not been.
     *
     * @return array<string, mixed>|null
     */
    public static function confirmation(Task $task): ?array
    {
        return $task->getValue('confirmed.'.static::name());
    }

    /**
     * The administrator says it is done. The task is read again under a lock, since the runner
     * saves its values too.
     *
     * @param  array<string, mixed>  $values
     */
    public static function confirm(Task $task, array $values = []): void
    {
        DB::transaction(function () use ($task, $values) {
            $current = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

            $current->custom_values = array_merge($current->customValues(), [
                'confirmed' => array_merge($current->getValue('confirmed') ?? [], [
                    static::name() => ['at' => now()->toDateTimeString(), 'values' => $values],
                ]),
            ]);
            $current->save();
        });
    }

    /**
     * The app's task that is waiting for a person, if one is.
     */
    public static function waiting(AppInstance $app_instance): ?Task
    {
        return Task::where('app_instance_id', $app_instance->id)
            ->whereIn('status', TaskStatus::openValues())
            ->orderByDesc('id')
            ->get()
            ->first(fn (Task $task) => is_subclass_of($task->getValue('awaiting.class'), self::class)
                // Not once it has been told
                && ($task->getValue('awaiting.class'))::confirmation($task) === null);
    }
}
