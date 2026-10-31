<?php

namespace App\Jobs;

use App\Exceptions\ConnectionFailedException;
use App\Support\Facades\Action;
use App\Support\Facades\Audit;
use App\Support\Facades\Organization;
use App\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAction implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REPORT_EVERY_N_ATTEMPTS = 30;

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
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle()
    {
        $started_at = microtime(true);
        $this->task->attempts = $this->task->attempts + 1;
        $this->task->status = 'in_progress';
        $this->task->save();

        // Everything logged while this task runs carries these fields (and jobs it queues inherit them).
        Context::add($this->taskContext());
        Log::info('task.started', ['description' => $this->task->description]);

        try {
            Organization::setOrganization($this->task->organization);
            $action = Action::run($this->task);

            if ($action) {

                if ($action->customValues() != $this->task->custom_values) {
                    $this->task->custom_values = $action->customValues();
                    $this->task->save();
                }
            }

            Log::info('task.run_finished', ['duration_ms' => $this->durationSince($started_at)]);
        } catch (ConnectionFailedException $e) {
            Log::warning('task.connection_failed', [
                'duration_ms' => $this->durationSince($started_at),
                'exception' => $e,
            ]);

            $this->task->job_id = 0;
            $this->task->status = 'pending';
            $this->task->error_code = 'connection_failed';
            $this->task->error_message = $e->getMessage();
            $this->task->save();

            // The task is retried every minute until the upstream recovers. Report the
            // first failure and then only periodically, so an outage doesn't flood alerts.
            if ($this->task->attempts <= 1 || $this->task->attempts % self::REPORT_EVERY_N_ATTEMPTS === 0) {
                report($e);
            }
        }
    }

    public function failed(Throwable $e)
    {
        try {
            Action::revert($this->task);
        } catch (Throwable $revert_exception) {
            report($revert_exception);
        }

        $this->task->job_id = 0;
        $this->task->status = 'failed';
        $this->task->error_code = 'throwable';
        $this->task->error_message = $e->getMessage();
        $this->task->save();

        Context::add($this->taskContext());
        Log::error('task.failed', ['exception' => $e]);

        Audit::recordTaskEvent('task.failed', $this->task, ['error' => $e->getMessage()], 'failure');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskContext(): array
    {
        return array_filter([
            'task_id' => $this->task->id,
            'action_slug' => $this->task->action_slug,
            'app_instance_id' => $this->task->app_instance_id,
            'organization_id' => $this->task->organization_id,
            'attempt' => $this->task->attempts,
        ], fn ($value) => $value !== null);
    }

    private function durationSince(float $started_at): int
    {
        return (int) round((microtime(true) - $started_at) * 1000);
    }
}
