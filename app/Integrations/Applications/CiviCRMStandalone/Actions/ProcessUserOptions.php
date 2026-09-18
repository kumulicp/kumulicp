<?php

namespace App\Integrations\Applications\CiviCRMStandalone\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\CiviCRMStandalone\API\User;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\AccountManager;
use App\Task;

class ProcessUserOptions extends Action
{
    public $slug = 'process_user_options';

    public $action_group = 'civicrm_standalone';

    public $background = true;

    public function __construct(AppInstance $app_instance, UserManager $user, $custom_values)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues($custom_values);
        $this->addCustomValue(['username' => $user->attribute('username')]);

        $this->description = __('actions.process_user');

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

        // The user hasn't logged into CiviCRM (via LDAP) yet, so there's no local record to update.
        if (! $user->exists()) {
            $task->delete();

            return;
        }

        try {
            $user->updateContact(array_filter([
                'first_name' => $task->getValue('first_name'),
                'last_name' => $task->getValue('last_name'),
                'email_primary.email' => $task->getValue('personal_email'),
            ]));
        } catch (ConnectionFailedException $e) {
            $task->error_message = $e->getMessage();
            $task->error_code = 'connection_failed';
            $task->restart();

            return;
        }

        if ($user->hasError()) {
            $task->error_message = $user->error();
            $task->status = 'pending';
            $task->error_code = 'update_user_options_failed';
            $task->save();

            return;
        }

        $task->delete();
    }

    public static function retry(Task $task)
    {
        $user = AccountManager::users()->find($task->getValue('username'));

        return new self($task->app_instance, $user, $task->customValues());
    }

    public static function complete(Task $task) {}
}
