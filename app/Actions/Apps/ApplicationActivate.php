<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\AppInstance;
use app\Application as App;
use App\AppPlan;
use App\AppVersion;
use App\Events\ApplicationActivated as EventApplicationActivated;
use App\Events\Apps\ApplicationActivating;
use App\Events\Apps\ApplicationPreActivation;
use App\Jobs\Applications\AddLdapGroups;
use App\Jobs\Apps\CreateApplicationDatabase;
use App\Notifications\ApplicationActivated;
use App\Organization;
use App\OrgSubdomain;
use App\Services\SubscriptionService;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Task;
use Illuminate\Support\Arr;

class ApplicationActivate extends Action
{
    public $slug = 'application_activate';

    private $sso_task;

    public function __construct(Organization $organization, App $app, AppPlan $plan, ?array $custom_values = [], ?AppInstance $parent_app_instance = null, ?AppVersion $version = null, ?string $label = null, ?OrgSubdomain $domain = null, ?array $configurations = null)
    {
        $this->organization = $organization;
        $this->app = $app;
        $this->plan = $plan;
        $this->version = $version ?? $app->active_version();
        if ($this->version->custom_values) {
            $this->setCustomValues(json_decode($this->version->custom_values, true));
        }
        $this->addCustomValue(['app_plan' => $plan->id]);
        $this->description = __('actions.activating').' '.$app->name;
        $prereqs = new Prerequisites;
        $is_shared_app = $plan->setting('server_type') === 'shared' && $plan->shared_app;

        if (is_array($custom_values) && array_key_exists('app_instance_id', $custom_values) && $app_instance = AppInstance::find($custom_values['app_instance_id'])) {
            $application = Application::instance($app_instance);
        } else {
            $parent_app_instance = $parent_app_instance ?? $is_shared_app ? $plan->shared_app : null;
            $application = Application::activate(
                organization: $organization,
                application: $app,
                version: $this->version,
                plan: $plan,
                parent_app: $parent_app_instance,
                label: $label,
                domain: $domain,
            );
            $custom_values['app_instance_id'] = $application->id;
        }

        if ($configurations) {
            $this->addCustomValue(['configurations' => $configurations]);

            foreach ($configurations as $key => $value) {
                $application->updateSetting('configurations.'.$key, $value);
            }
        }

        if ($custom_values) {
            $this->addCustomValue($custom_values);
        }

        $application->features()->update($custom_values);

        if ($parent_app_instance) {
            $prereqs->add_application_required($parent_app_instance);
        }

        $prereqs->add_subscription_active();
        if (! Arr::has($custom_values, 'sso_task_id') && $this->plan->sso_server) {
            if ($this->sso_task = ActionFacade::execute(new ApplicationSSOSetup($application->get()), background: true)) {
                $prereqs->add_waiting_for($this->sso_task);
                $this->addCustomValue(['sso_task_id' => $this->sso_task->id]);
            }
        }
        $this->prerequisites = $prereqs->get();

        $application->refresh();
        $this->app_instance = $application->app_instance;

        ApplicationPreActivation::dispatch($this->app_instance);

        if ($is_shared_app) {
            $this->status = 'in_progress';
            $this->addCustomValue(['shared_app' => true]);
        }
    }

    public function postGenerate(Task $task)
    {
        if (! $task->getValue('shared_app')) {
            // Add ldap groups
            AddLdapGroups::dispatch($task->app_instance);

            if ($this->sso_task) {
                $task_values = $this->sso_task?->custom_values;
                $new_values = Arr::set($task_values, 'parent_task_id', $task->id);
                $this->sso_task->custom_values = $new_values;
                $this->sso_task->save();
            }
        }
    }

    public static function run(Task $task)
    {
        $plan = AppPlan::find($task->getValue('app_plan'));
        $app_instance = Application::instance($task->app_instance);
        $app_activate = new self($task->organization, $task->application, $plan, $task->customValues());
        $app_profile = Application::profile($app_instance->application->slug);

        // Add ldap groups
        AddLdapGroups::dispatch($task->app_instance);

        if ($plan->database_server && ! $app_instance->databasename) {
            // Add app database
            CreateApplicationDatabase::dispatch($task->app_instance, $task);
        }

        // Only parent app is responsible for activating app
        if ($app_profile->activationType() === 'chart') {
            $server = $app_instance->connect('web');
            if ($server->existsOrganization()) {
                $server->add();
            } else {
                $server->addOrganization();
                $task->restart();
            }
        } elseif ($app_profile->activationType() === 'job') {
            $waiting_for = [];
            $new_task = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'activate'), $task, background: true);
            $parent_app_task = ActionFacade::execute(new ApplicationUpgrade($app_instance->parent, $app_instance->parent->version), $task, background: true);
            $waiting_for[] = $new_task->id;
            $waiting_for[] = $parent_app_task->id;
            $app_activate->addCustomValue(['waiting_for' => $waiting_for]);
        }

        // Create task to process customizations
        ActionFacade::execute(new ProcessCustomizations($task->app_instance, $task->customValues()), $task, delay: true);
        ApplicationActivating::dispatch($task->app_instance->refresh());

        return $app_activate;
    }

    public static function retry(Task $task)
    {
        if ($task->action_slug == 'activate') {
            $task->status = 'ready';
            $task->save();
        } else {
            $plan_id = $task->getValue('app_plan');
            $plan = AppPlan::find($plan_id);

            return new self($task->organization, $task->application, $plan, $task->custom_values);
        }
    }

    public static function complete(Task &$task)
    {
        OrganizationFacade::setOrganization($task->organization);
        $app_instance = Application::instance($task->app_instance);
        $task_complete = false;
        if ($app_instance && ! $task->getValue('shared_app')) {
            $server = $app_instance->connect('web');

            if ($server->isActive()) {
                // Get roles if any

                $default_admin_roles = $task->version->defaultAdminRoleSlugs();

                // Process permissions
                foreach ($task->organization->admins() as $user) {
                    $parent_task = $task;

                    $permissions = $user->permissions();
                    $permissions->updateAppRoles($app_instance->get(), $default_admin_roles);

                    $new_task = ActionFacade::dispatch(
                        category: $app_instance->application->slug,
                        action: 'process_permissions',
                        params: [$app_instance->app_instance, [
                            'permission' => $default_admin_roles,
                            'user' => $user->attribute('username'),
                        ]],
                        parent_task: $parent_task);

                    $new_task = ActionFacade::dispatch(
                        category: $app_instance->application->slug,
                        action: 'process_user_options',
                        params: [$app_instance->app_instance, $user, []],
                        parent_task: $parent_task);
                }

                if ($app_instance->primary_domain?->domain->type === 'managed') {
                    ActionFacade::execute(new UpdateDnsRecords($app_instance->organization, $app_instance->primary_domain->domain));
                }

                $task_complete = true;
            }
        } elseif ($task->getValue('shared_app')) {
            $task_complete = true;
        }

        if ($task_complete) {
            $app_instance->status = 'active';
            $app_instance->save();
            EventApplicationActivated::dispatch($app_instance->get());

            $subscription = (new SubscriptionService($task->organization))->all();
            ActionFacade::execute(new SubscriptionUpdate($task->organization, $subscription), background: true);
            $task->complete();

            $task->organization->notifyAdmins(new ApplicationActivated($task));
            $task->groupNotified();
        }
    }
}
