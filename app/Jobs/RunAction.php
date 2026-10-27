<?php

namespace App\Jobs;

use App\Exceptions\ConnectionFailedException;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunAction implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A task is never run twice by the queue itself: a retry (after a connection
    // failure, or by an admin) is a new dispatch from the task's own state. A job
    // the queue redelivers after retry_after expires fails (and reverts) instead
    // of running again alongside the original.
    public $tries = 1;

    // Set in the constructor from the connection's retry_after (see below)
    public $timeout = null;

    // Seconds the queue allows a margin before retry_after, so a job is killed
    // before the queue would hand it to a second worker.
    private const RETRY_AFTER_MARGIN = 30;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        public Task $task
    ) {
        $action = Action::classFor($task);

        if ($action && $action::$long_running && $connection = config('queue.long_running_connection')) {
            $this->onConnection($connection)->onQueue('long');
        }

        $retry_after = config('queue.connections.'.($this->connection ?? config('queue.default')).'.retry_after');

        if ($retry_after) {
            $this->timeout = max($retry_after - self::RETRY_AFTER_MARGIN, 1);
        }
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle()
    {
        $this->task->attempts = $this->task->attempts + 1;
        $this->task->status = 'in_progress';
        $this->task->save();

        try {
            Organization::setOrganization($this->task->organization);
            $action = Action::run($this->task);

            if ($action) {

                if ($action->customValues() != $this->task->custom_values) {
                    $this->task->custom_values = $action->customValues();
                    $this->task->save();
                }
            }
        } catch (ConnectionFailedException $e) {
            $this->task->job_id = 0;
            $this->task->status = 'pending';
            $this->task->error_code = 'connection_failed';
            $this->task->error_message = $e->getMessage();
            $this->task->save();

            report($e);
        }
    }

    public function failed(Throwable $e)
    {
        Action::revert($this->task);
        $this->task->job_id = 0;
        $this->task->status = 'failed';
        $this->task->error_code = 'throwable';
        $this->task->error_message = $e->getMessage();
        $this->task->save();
    }
}
