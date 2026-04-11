<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Support\AccountManager\UserManager;
use App\Support\ByteConversion;
use App\Support\Facades\AccountManager;

class ProcessUserOptions extends Action
{
    public $slug = 'process_user_options';

    public $action_group = 'nextcloud';

    public $background = 1;

    // Used for action tasks that
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

    public static function run($task)
    {
        $username = $task->getValue('username');
        $user = AccountManager::users()->find($username);
        $conversion = new ByteConversion;
        $nextcloud_user = new Users($task->app_instance);

        $found = false;
        try {
            $found = $nextcloud_user->find($username);
        } catch (\Throwable $e) {
            $found = false;
        }

        if ($found) {
            $users = $nextcloud_user->update($user, 'quota', $conversion($user->appStorage($task->app_instance), 'gb', 'b', 'byte'));
        } else {
        }
    }

    public static function retry($task)
    {
        $user = AccountManager::users()->find($task->getValue('username'));

        return new self($task->app_instance, $user, $task->customValues());
    }

    public static function complete($task)
    {
        $username = $task->getValue('username');
        $user = AccountManager::users()->find($username);
        $conversion = new ByteConversion;

        try {
            $nextcloud_quota = (int) (new Users($task->app_instance))->find($username)->quota->quota;

            if ($nextcloud_quota == $conversion($user->appStorage($task->app_instance), 'gb', 'b', 'byte')) {
                $task->complete();
                $task->notified();
                $task->delete();
            } else {
                throw \Exception(__('actions.nextcloud.quota_not_changed'))
            }
        } catch (\Throwable $e) {
            $task->status = 'pending';
            $task->error_message = $e->getMessage();
            $task->error_code = 'user_not_found';
            $task->save();
        }
    }
}
