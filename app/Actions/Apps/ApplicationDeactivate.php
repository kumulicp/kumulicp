<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Jobs\Applications\RemoveLDAPGroups;
use App\Notifications\ApplicationDeactivated;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;

class ApplicationDeactivate extends Action
{
    public $slug = 'application_deactivate';

    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $app_instance->status = 'deactivating';
        $app_instance->save();

        foreach ($app_instance->children as $child) {
            $child->status = 'deactivating';
            $child->save();
        }

        $this->app_instance = $app_instance;

        $this->description = __('actions.deactivating_app', ['app' => $app_instance->label]);

        $this->background = 1;
    }

    public static function run(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);

        $application_deactivate = new self($app_instance->app_instance, $app_instance->version);
        RemoveLDAPGroups::dispatch($task->app_instance);

        if (Arr::get(Application::get($app_instance->application->slug), 'activation_type') == 'job' && $job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'upgrade'), null, true)) {
            $application_deactivate->addCustomValue(['waiting_for' => [$job->id]]);

            $parent_app = Application::instance($app_instance->parent);
            $parent_app->connect('web')->update();
        } else {
            $server = $app_instance->connect('web');
            $server->update();
        }

        return $application_deactivate;
    }

    public static function retry(Task $task)
    {
        $task->status = 'ready';
        $task->save();

        return new self($task->app_instance);
    }

    public static function complete(Task $task)
    {
        if ($app_instance = Application::instance($task->app_instance)) {
            $server = $app_instance->connect('web');
            if (! $server->isActive()) {
                $app_instance->additional_storage()->delete();
                $app_instance->status = 'deactivated';
                $app_instance->save();

                foreach ($app_instance->children as $child) {
                    $child->status = 'deactivated';
                    $child->save();
                }

                AccountManager::users()->updateAllUsersAccessType();

                $subscription = (new SubscriptionService($task->organization))->all();
                ActionFacade::execute(new SubscriptionUpdate($task->organization, $subscription), background: true);

                $task->organization->notifyAdmins(new ApplicationDeactivated($app_instance->get()));

                $task->complete();
                $task->groupNotified();
            }
        }
    }
}
