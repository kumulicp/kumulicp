<?php

namespace App\Jobs\Apps;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateApplicationSSO
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(public AppInstance $app_instance, public ?Task $task = null) {}

    /**
     * Handle the event.
     *
     * @param  ApplicationActivating  $event
     * @return void
     */
    public function handle()
    {
        $app_instance = Application::instance($this->app_instance);
        $organization = $app_instance->organization;

        try {
            $sso = $app_instance->connect('sso');

            if ($sso && $app_instance->setting('sso') && $sso->exists()) {
                $response = $sso->update();
                $app_instance->updateSetting('sso', $response);
                $app_instance->status = 'activating';
                $app_instance->save();
            }
        } catch (Throwable $e) {
            if ($this->task) {
                $this->task->status = 'failed';
                $this->task->error_message = $e->getMessage();
                $this->save();
            }
        }
    }
}
