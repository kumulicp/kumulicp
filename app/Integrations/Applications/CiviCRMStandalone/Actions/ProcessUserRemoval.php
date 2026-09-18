<?php

namespace App\Integrations\Applications\CiviCRMStandalone\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\CiviCRMStandalone\API\User;
use App\Task;

class ProcessUserRemoval extends Action
{
    public $slug = 'process_user_removal';

    public $action_group = 'civicrm_standalone';

    public $background = true;

    public function __construct(AppInstance $app_instance, string $username)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->addCustomValue(['username' => $username]);

        $this->description = __('actions.process_user_removal');

        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);
        $this->prerequisites = $prereqs->get();
    }

    public static function run(Task $task)
    {
        $username = $task->getValue('username');

        $user = new User($task->app_instance);

        try {
            $user->find($username);
        } catch (ConnectionFailedException $e) {
            $task->error_message = $e->getMessage();
            $task->error_code = 'connection_failed';
            $task->restart();

            return;
        }

        // Never provisioned in CiviCRM (e.g. they never logged in via LDAP) -- nothing to deactivate.
        if (! $user->exists()) {
            $task->delete();

            return;
        }

        try {
            $user->deactivate();
        } catch (ConnectionFailedException $e) {
            $task->error_message = $e->getMessage();
            $task->error_code = 'connection_failed';
            $task->restart();

            return;
        }

        if ($user->hasError()) {
            $task->error_message = $user->error();
            $task->status = 'pending';
            $task->error_code = 'deactivate_user_failed';
            $task->save();

            return;
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->getValue('username'));
    }

    public static function complete(Task $task) {}
}
