<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Support\Facades\AccountManager;
use App\Task;

class ProcessPermissions extends Action
{
    public $slug = 'process_permissions';

    public $action_group = 'nextcloud';

    public $background = true;

    public function __construct(AppInstance $app_instance, $custom_values)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->custom_values = $custom_values;

        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);
        $this->prerequisites = $prereqs->get();
        $this->description = __('actions.process_permissions', ['app' => $app_instance->label]);
    }

    public static function run(Task $task)
    {
        $user = new Users($task->app_instance);
        try {
            $user->find($task->getValue('user'));
        } catch (\Throwable $e) {
            if ($user->statusCode() != 404) {
                report($e);
            }

            if (AccountManager::driver() == 'direct') {
                $user->add($organization_values['user']);
            }

            $task->error_message = $e->getMessage();
            $task->error_code = 'process_permissions';
            $task->status = 'pending';
            $task->save();

            return;
        }
        $permissions = $task->getValue('permission');

        $is_admin = $user->checkPermission('admin');

        if (! $is_admin && array_key_exists('nextcloud_admin', $permissions) && $permissions['nextcloud_admin'] === true) {
            $user->addToGroup('admin');
        } elseif ($is_admin && (! array_key_exists('nextcloud_admin', $permissions) || $permissions['nextcloud_admin'] === false)) {
            $user->removeFromGroup('admin');
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->customValues());
    }

    public static function complete($task) {}
}
