<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Jobs\Applications\RemoveLDAPGroups;
use App\Services\SubscriptionService;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;

class ApplicationDelete extends NativeAction
{
    public $slug = 'application_delete';

    public static bool $long_running = true;

    public function __construct(AppInstance $app_instance, ?string $start_time = null, ?string $end_time = null)
    {
        // Other instances' parent_id points here (shared-app children, or a
        // type-level "same org" parent) -- deleting it out from under them
        // orphans the FK and breaks anything that assumes a shared child's
        // ->parent still resolves (e.g. LdapSupport::getAppRoleGroup()).
        if ($app_instance->children()->notDeactivated()->exists()) {
            throw new \Exception(__('messages.exception.shared_app_has_children', ['app' => $app_instance->label]));
        }

        $this->organization = $app_instance->organization;
        $this->app_instance = Application::instance($app_instance);

        if ($start_time) {
            $prereq = new Prerequisites;
            $prereq->add_time_range($start_time, $end_time);
            $this->prerequisites = $prereq->get();
        }

        $this->description = __('actions.deleting_app', ['app' => $app_instance->application->name]);

        $this->background = 1;
    }

    // Once the task exists, and again when it is retried
    public function onCreate(Task $task)
    {
        $this->app_instance->status = 'deleting';
        $this->app_instance->save();
    }

    public static function handle(Task $task): ActionResult
    {
        $app_instance = Application::instance($task->app_instance);
        $result = ActionResult::wait();
        $app_profile = Application::profile($app_instance->application->slug);
        // Not queued: the app is deleted as soon as it is gone from its server, and a
        // queued job would find the instance already deleted and never remove the groups
        RemoveLDAPGroups::dispatchSync($task->app_instance);

        // A shared child without multisite has no release/site of its own
        // to delete -- deleting the LDAP groups above is all it needs.
        if ($app_instance->usesOwnResources()) {
            // Delete App
            if ($app_profile->activationType($app_instance->get()) === 'chart') {  // Delete via chart
                try {
                    $web_server = $app_instance->connect('web');
                    $web_server->delete();
                } catch (\Throwable $e) {
                    report($e);
                }
            } elseif ($app_profile->activationType($app_instance->get()) === 'job') {  // Delete via job
                if ($job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'deactivate'), $task)) {
                    // Need to wait until this new job task is complete
                    $result = $result->waitingFor($job);
                }
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

        return $result;
    }

    /**
     * An app whose delete hadn't started goes back to active. One that had is
     * partly deleted: it stays as it is.
     */
    public static function cancelled(Task $task): ?string
    {
        $app_instance = $task->app_instance;

        if (! $app_instance || $app_instance->status !== 'deleting') {
            return null;
        }

        if ($task->attempts > 0) {
            return __('messages.action.cancelled_delete_started');
        }

        $app_instance->status = 'active';
        $app_instance->save();

        return null;
    }

    public static function retry(Task $task): ?Action
    {
        return new self($task->app_instance);
    }

    public static function poll(Task $task): ActionResult
    {
        $app_instance = Application::instance($task->app_instance);
        $complete = false;

        if (! $app_instance->usesOwnResources()) {
            // A shared child without multisite had nothing of its own
            // deleted in handle() above beyond its LDAP groups -- nothing left
            // to wait on.
            $complete = true;
        } elseif (Application::profile($app_instance->application->slug)->activationType($app_instance->get()) === 'job') {
            // A site deleted by a job inside the hub's release (e.g. ERPNext):
            // the job is what this waited for and it is complete or gone by
            // the time this is polled. The release itself stays deployed, so it
            // can't say whether the site is gone.
            $complete = true;
        } else {
            try {
                // connect('web')->isActive() reports the app's own release
                $complete = ! $app_instance->connect('web')->isActive();
            } catch (\Throwable $e) {
                // No web server assigned -- nothing to wait on.
                $complete = true;
            }
        }

        if ($complete) {
            $organization = $task->organization;

            // Deleting the app's tasks (this one included) is how it says it has
            // finished: the tasks waiting for them are told once it is all gone
            $runner = app(TaskRunner::class);
            $waiting = $runner->dependentsOf($app_instance->tasks()->pluck('id')->push($task->id));

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

            $runner->wake($waiting);
        }

        // Done: the app's tasks, this one included, are deleted with it, so
        // there is no task left to mark complete
        return ActionResult::wait();
    }
}
