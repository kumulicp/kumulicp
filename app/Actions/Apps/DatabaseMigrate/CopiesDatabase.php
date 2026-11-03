<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Steps\Step;
use App\OrgBackup;
use App\Task;

/**
 * A backup or restore of the app's database. The driver may do the work before
 * it returns, or hand it to another service that reports back when it is done.
 */
abstract class CopiesDatabase extends Step
{
    /** The custom value holding the id of the OrgBackup record this step makes */
    abstract protected function recordKey(): string;

    public function timeoutMinutes(): ?int
    {
        return ApplicationDatabaseMigrate::COPY_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return __('messages.database_migration.copy_timed_out', ['step' => __('labels.'.$this->record($task)->action)]);
    }

    /**
     * Whether the copy has finished: done if it has, fail if it failed, wait if
     * it is still going (the timeout is the runner's).
     */
    public function check(Task $task): ActionResult
    {
        $backup = $this->record($task);
        $backup->refresh();

        if ($backup->status === 'completed') {
            return ActionResult::done();
        }

        if ($backup->status !== 'running') {
            return ActionResult::fail(__('messages.database_migration.copy_failed', ['step' => __('labels.'.$backup->action)]));
        }

        return ActionResult::wait();
    }

    protected function record(Task $task): OrgBackup
    {
        return OrgBackup::findOrFail($task->getValue($this->recordKey()));
    }
}
