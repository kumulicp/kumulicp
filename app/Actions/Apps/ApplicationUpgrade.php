<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\AppVersion;
use App\Events\AppInstanceSubscriptionChanged;
use App\Jobs\Applications\AddLdapGroups;
use App\Notifications\ApplicationUpgraded;
use App\Services\AppInstance\AppStorageService;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;

class ApplicationUpgrade extends Action
{
    public $slug = 'application_upgrade';

    public $background = false;

    public function __construct(AppInstance $app_instance, AppVersion $version, ?Prerequisites $prerequisites = null, bool $notify = true)
    {
        $this->organization = $app_instance->organization;
        $this->version = $version;
        if ($prerequisites) {
            $this->setCustomValues($prerequisites);
        }
        $this->addCustomValue(['notify' => $notify]);

        $application = $version->application;

        $this->description = __('actions.upgrading_app', ['app' => $application->name]);
        $app_instance->version_id = $version->id;
        $app_instance->status = 'updating';
        $app_instance->save();
        $this->app_instance = $app_instance;

        if (Arr::get(Application::get($app_instance->application->slug), 'activation_type') == 'job' && $job = ActionFacade::execute(new ApplicationUpdateJob($app_instance, 'upgrade'), null, true)) {
            $this->addCustomValue(['waiting_for' => [$job->id]]);
        }
    }

    public static function run(Task $task)
    {
        // Add ldap groups
        AddLdapGroups::dispatch($task->app_instance);
        $app_instance = Application::instance($task->app_instance);
        if (Arr::get(Application::get($app_instance->application->slug), 'activation_type') == 'job' && $parent_app = Application::instance($app_instance->parent)) {
            $parent_app->connect('web')->update();
        } else {
            $server = $app_instance->connect('web')->update();
        }

        if ($app_instance->plan->sso_server) {
            ActionFacade::execute(new ApplicationSSOSetup($app_instance->get()), background: true);
        }

        AppInstanceSubscriptionChanged::dispatch($task->app_instance);

        if ($app_instance->version->customizations) {
            ActionFacade::execute(new ProcessCustomizations($task->app_instance, $app_instance->customizationsToArray()), $task);
        }

        // Check if upgrade required
        $app_storage_service = new AppStorageService($task->app_instance);
        $calculated_storage = $app_storage_service->calculateTotalAppStorage();
        $app_storage_quota = $app_instance->setting('storage_quota');

        if ($calculated_storage > $app_storage_quota) {
            // Setting expand_storage to true triggers protocol to expand storage in kubernetes
            $app_instance->updateSetting('expand_storage', true);
            $app_instance->updateSetting('storage_quota', $calculated_storage);
        }
    }

    public static function retry(Task $task)
    {
        $app_instance = $task->app_instance;
        $app_instance->status = 'updating';
        $app_instance->save();

        $task->status = 'ready';
        $task->save();

        return new self($app_instance, $task->version);
    }

    public static function complete(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);
        $server = $app_instance->connect('web');

        if ($app_instance->setting('expand_storage') == true && $task->update_at < now()->subMinutes(5)) {
            $app_instance->updateSetting('expand_storage', false);

            // Run another update to restore app
            $server->update();
            sleep(5);
        } elseif ($app_instance->setting('expand_storage') == true && $task->update_at >= now()->subMinutes(5)) {
            return;
        }

        if ($server->isActive()) {
            // Set app status to active
            $app_instance->version_id = $task->version_id;
            $app_instance->status = 'active';
            $app_instance->save();

            $task->complete();

            if ($task->getValue('notify') && in_array($app_instance->version->announcement_location, ['local', 'remote'])) {
                $task->organization->notifyAdmins(new ApplicationUpgraded($task));
                $task->groupNotified();
            }
        }
    }
}
