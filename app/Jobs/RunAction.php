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

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        public Task $task
    ) {}

    /**
     * Handle the event.
     *
     * @param  UpdateOrganization  $event
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

                if ($action->customValues() != $this->task->organization_values) {
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
