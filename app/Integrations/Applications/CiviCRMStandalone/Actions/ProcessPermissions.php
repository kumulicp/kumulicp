<?php

namespace App\Integrations\Applications\CiviCRMStandalone\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\CiviCRMStandalone\API\User;
use App\Task;
use Illuminate\Support\Arr;

class ProcessPermissions extends Action
{
    public $slug = 'process_permissions';

    public $action_group = 'civicrm_standalone';

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
        $username = $task->getValue('user');
        $permissions = $task->getValue('permission');

        $user = new User($task->app_instance);

        $roles = [];

        foreach (Arr::get($permissions, $task->app_instance->id, []) as $permission) {
            if ($permission) {
                $roles[] = $permission;
            }
        }

        try {
            $user->find($username);
        } catch (ConnectionFailedException $e) {
            $task->error_message = $e->getMessage();
            $task->error_code = 'connection_failed';
            $task->restart();

            return;
        }

        if (! $user->exists()) {
            try {
                $user->create($username, $roles);
            } catch (ConnectionFailedException $e) {
                $task->error_message = $e->getMessage();
                $task->error_code = 'connection_failed';
                $task->restart();

                return;
            }

            if ($user->hasError()) {
                $task->error_message = $user->error();
                $task->status = 'pending';
                $task->error_code = 'create_user_failed';
                $task->save();

                return;
            }
        } else {
            try {
                $user->updateRoles($roles);
            } catch (ConnectionFailedException $e) {
                $task->error_message = $e->getMessage();
                $task->error_code = 'connection_failed';
                $task->restart();

                return;
            }
        }

        if ($user->hasError()) {
            $task->error_message = $user->error();
            $task->status = 'pending';
            $task->error_code = 'update_user_roles_failed';
            $task->save();

            return;
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->customValues());
    }

    public static function complete(Task $task) {}
}
