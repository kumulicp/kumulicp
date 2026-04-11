<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Actions\Action;
use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Apps;
use App\Task;

class ManageAddon extends Action
{
    public $slug = 'manage_addon';

    public $action_group = 'nextcloud';

    public $background = 1;

    public function __construct(AppInstance $app_instance, $values)
    {
        $this->organization = $app_instance->organization;
        $this->setCustomValues($values);
        $this->app_instance = $app_instance;

        $this->description = __('actions.nextcloud.update_addons');
        $this->action_group = $app_instance->application->slug;
    }

    public static function run(Task $task)
    {
        $app = new Apps($task->app_instance);
        $addon_name = $task->getValue('name');
        $enabled = $app->isEnabled($addon_name);

        if (! $enabled && $task->getValue('status') == 'enabled') {
            $app->enable($task->getValue('name'));
        } elseif ($enabled && $task->getValue('status') == 'disabled') {
            $app->disable($task->getValue('name'));
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->customValues());
    }

    public static function complete(Task $task) {}
}
