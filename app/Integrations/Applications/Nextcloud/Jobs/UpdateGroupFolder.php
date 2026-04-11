<?php

namespace App\Integrations\Applications\Nextcloud\Jobs;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\Services\GroupFolderService;
use App\Services\AdditionalStorageService;
use App\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

class UpdateGroupFolder
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $app_instance;

    public $values;

    public $organization;

    public $task;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(AppInstance $app_instance, $values, ?Task $task = null)
    {
        $this->app_instance = $app_instance;
        $this->values = $values;
        $this->organization = $app_instance->organization;
        $this->task = $task;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $options = $this->values;

        try {
            // Get group name
            $group_name = array_key_exists('original_name', $options) ? $options['original_name'] : $options['name'];

            $group_folder_service = new GroupFolderService($this->app_instance, $group_name);
            $additional_storage = Arr::get($options, 'extensions.nextcloud_additional_storage', null);
            // Get group nextcloud quota
            if ($additional_storage && is_int((int) $additional_storage)) {
                $additional_storage = new AdditionalStorageService($this->organization, 'group', $group_name, $this->app_instance);
            }

            if ($group_folder_service->group_folder->exists()) {
                // Get group name
                if (array_key_exists('original_name', $options) && $options['original_name'] != $options['name']) {
                    $group_folder_service->update($options['name']);
                }
            } else {
                $group_folder_service->add($options['name']);
            }

            foreach ($options['managers'] as $manager) {
                $managers[] = $manager;
            }

            $group_folder_service->updateManagers($managers);
            $group_folder_service->updateQuota($additional_storage->quantity());
        } catch (Throwable $e) {
            report($e);
            if ($this->task) {
                $this->task->error_message = $e->getMessage();
                $this->task->status = 'failed';
                $this->task->save();
            }
        }
    }

    public function failed(Throwable $e) {}
}
