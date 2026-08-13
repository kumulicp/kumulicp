<?php

namespace App\Jobs\Apps;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateApplicationDatabase
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $app_instance;

    public $task;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(AppInstance $app_instance, ?Task $task = null)
    {
        $this->app_instance = $app_instance;
        $this->task = $task;
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle()
    {
        $app_instance = Application::instance($this->app_instance);
        $organization = $app_instance->organization;

        try {
            $database = $app_instance->connect('database');

            if ($database) {
                if ($app_instance->databasename && $database->exists()) {
                    return;
                }

                $response = $database->add();

                $app_instance->server_database_id = $response['id'];
                $app_instance->databasename = $response['databasename'];
                $app_instance->status = 'activating';
                $app_instance->save();
            } else {
                throw new \Exception(__('messages.exception.database_failed'));
            }
        } catch (Throwable $e) {
            if ($this->task) {
                $this->task->status = 'failed';
                $this->task->error_message = $e->getMessage();
                $this->task->save();
            }
        }
    }
}
