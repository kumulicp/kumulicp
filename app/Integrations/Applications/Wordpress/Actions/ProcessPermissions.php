<?php

namespace App\Integrations\Applications\Wordpress\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\Wordpress\API\User;
use App\Task;
use Illuminate\Support\Arr;

class ProcessPermissions extends Action
{
    public $slug = 'process_permissions';

    public $action_group = 'wordpress';

    public $status = 'in_progress';

    public $background = true;

    public function __construct(AppInstance $app_instance, $custom_values)
    {
        $this->app_instance = $app_instance;
        $this->organization = $app_instance->organization;
        $this->custom_values = $custom_values;

        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);
        $this->prerequisites = $prereqs->get();
        $this->description = __('actions.process_permissions', ['app' => $app_instance->label]);
    }

    // Used for action tasks that
    public static function run($task = null)
    {
        $custom_values = $task->customValues();
        $username = $custom_values['user'];
        $permissions = $custom_values['permission'];

        if (Arr::has($permissions, $task->app_instance->id, null)) {
            $user = new User($task->app_instance);

            $roles = [];
            foreach ($permissions[$task->app_instance->id] as $permission) {
                if ($permission) {
                    $roles[] = $permission;
                }
            }

            foreach ($task->app_instance->children as $child) {
                foreach (Arr::get($permissions, $child->id, []) as $permission) {
                    if ($permission) {
                        $roles[] = $permission;
                    }
                }
            }

            try {
                $response = $user->updateUserRoles($username, $roles);
            } catch (ConnectionFailedException $e) {
                $task->error_message = $e->getMessage();
                $task->error_code = 'connection_failed';
                $task->restart();

                return;
            }

            if ($user->hasError()) {
                $task->error_message = $user->error();
                $task->status = 'pending';
                $task->error_code = 'update_user_role_failed';
                $task->save();
            }
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->customValues());
    }

    public static function complete(Task $task) {}
}
