<?php

namespace App\Integrations\Applications\DemoApp\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\AccountManager;
use Illuminate\Support\Arr;

class ProcessGroupOptions extends Action
{
    public $slug = 'process_group_options';

    public $action_group = 'demo_app';

    public $status = 'in_progress';

    public $background = false;

    public function __construct(AppInstance $app_instance, GroupManager $group, $custom_values)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues($custom_values);
        $this->addCustomValue(['group_slug' => $group->attribute('slug')]);

        $this->description = __('actions.process_groups');

        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);
        $this->prerequisites = $prereqs->get();

        $name = array_key_exists('original_name', $custom_values)
            ? $custom_values['original_name']
            : ($custom_values['name'] ?? $group->attribute('slug'));

        $additional_storage_service = new AdditionalStorageService(
            $this->organization,
            'group',
            $name,
            $this->app_instance
        );

        if (Arr::get($custom_values, 'extensions.demo_group_storage', false) == true) {
            $additional_storage_service->updateQuantity(
                (int) Arr::get($custom_values, 'extensions.demo_additional_storage', 1)
            );
        } else {
            $additional_storage_service->delete();
        }
    }

    public static function run($task): void
    {
        $task->status = 'complete';
        $task->save();
    }

    public static function retry($task): self
    {
        $group = AccountManager::groups()->find($task->getValue('group_slug'));

        return new self($task->app_instance, $group, $task->customValues());
    }

    public static function complete($task): void
    {
        if ($task->status === 'complete') {
            $task->delete();
        }
    }
}
