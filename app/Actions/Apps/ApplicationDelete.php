<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Jobs\Applications\RemoveLDAPGroups;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;

class ApplicationDelete extends Action
{
    public $slug = 'application_delete';

    public function __construct(AppInstance $app_instance, ?Prerequisites $prerequisites = null, ?string $start_time = null, ?string $end_time = null)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;

        $this->app_instance->status = 'deleting';
        $this->app_instance->save();

        if ($start_time) {
            $prereq = new Prerequisites;
            $prereq->add_time_range($start_time, $end_time);
            $this->prerequisites = $prereq->get();
        }

        $this->description = __('actions.deleting_app', ['app' => $app_instance->application->name]);

        $this->background = 1;
    }

    public static function run(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);
        $parent_app = $app_instance->application->parent_app;
        $app_delete = new self($task->app_instance);
        $app_profile = Application::get($app_instance->application->slug);
        RemoveLDAPGroups::dispatch($task->app_instance);

        // Delete App
        if (Arr::get($app_profile, 'activation_type', 'chart') === 'chart') {  // Delete via chart
            $web_server = $app_instance->connect('web');
            $web_server->delete();
        } elseif (Arr::get($app_profile, 'activation_type') === 'job') {  // Delete via job
            if ($job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'deactivate'), $task)) {
                // Need to wait until this new job task is complete
                $app_delete->addCustomValue(['waiting_for' => [$job->id]]);
            }
        }

        // Delete Database
        if ($app_instance->databasename !== null) {
            $failed = false;
            try {
                $database_server = $app_instance->connect('database');
                $database_server->delete();
            } catch (\Throwable $e) {
                report($e);
                $failed = true;
            }

            if (! $failed) {
                $app_instance->databasename = null;
                $app_instance->save();
            }
        }

        // Delete SSO Configurations
        if ($app_instance->sso_server_id) {
            $failed = false;
            try {
                $sso_server = $app_instance->connect('sso');
                $sso_server->delete();
            } catch (\Throwable $e) {
                report($e);
                $failed = true;
            }

            if (! $failed) {
                $app_instance->updateSetting('sso', null);
                $app_instance->save();
            }
        }

        return $app_delete;
    }

    public static function retry(Task $task)
    {
        $task->status = 'ready';
        $task->save();

        return new self($task->app_instance);
    }

    public static function complete(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);
        $complete = false;

        if ($app_instance->parent) {
            $complete = true;
        } else {
            $server = $app_instance->connect('web');
            if (! $server->isActive()) {
                $complete = true;
            }
        }

        if ($complete) {
            $organization = $task->organization;

            AccountManager::users()->updateAllUsersAccessType();

            foreach ($app_instance->domains as $domain) {
                // Only delete domains that are subdomains (which means they don't have a parent domain
                if ($domain->parent_domain) {
                    $domain->delete();
                } else {
                    $domain->app_instance_id = null;
                    $domain->save();
                }
            }
            $app_instance->tasks()->delete();
            $app_instance->additional_storage()->delete();

            $app_instance->delete();

            $subscription = (new SubscriptionService($organization))->all();
            ActionFacade::execute(new SubscriptionUpdate($organization, $subscription), background: true, delay: true);
        }
    }
}
