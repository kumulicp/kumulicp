<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\NativeAction;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Application as App;
use App\AppPlan;
use App\AppVersion;
use App\Enums\TaskErrorCode;
use App\Enums\TaskStatus;
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

class ApplicationActivate extends NativeAction
{
    public $slug = 'application_activate';

    public static bool $long_running = true;

    private AppPlan $plan;

    // What to create the instance with. Only used by onCreate() when the task is
    // for a new activation; a retry already has its instance.
    private ?AppInstance $parent_app_instance = null;

    private ?string $label;

    private ?OrgSubdomain $domain;

    /** @var array<string, mixed>|null */
    private ?array $configurations;

    /** @var array<string, mixed> What the customer chose to customize, as the request sent it */
    private array $customizations;

    /**
     * Stores what the activation is for. Nothing is created or changed here:
     * the instance is made by onCreate(), once the task exists.
     *
     * $custom_values is the customizations from the request or, when the action
     * is rebuilt for a retry, everything the task already holds (including the
     * app_instance_id of the instance it made).
     */
    public function __construct(Organization $organization, App $app, AppPlan $plan, ?array $custom_values = [], ?AppInstance $parent_app_instance = null, ?AppVersion $version = null, ?string $label = null, ?OrgSubdomain $domain = null, ?array $configurations = null)
    {
        $custom_values ??= [];

        $this->organization = $organization;
        $this->application = $app;
        $this->plan = $plan;
        $this->version = $version ?? $app->active_version();
        $this->label = $label;
        $this->domain = $domain;
        $this->configurations = $configurations;
        $this->customizations = $custom_values;
        if ($this->version->custom_values) {
            $this->setCustomValues(json_decode($this->version->custom_values, true));
        }
        $this->addCustomValue(['app_plan' => $plan->id]);
        $this->description = __('actions.activating').' '.$app->name;
        $prereqs = new Prerequisites;
        $is_shared_app = $plan->setting('server_type') === 'shared' && $plan->shared_app;

        // A new activation; a rebuilt one has its instance already
        if (! (array_key_exists('app_instance_id', $custom_values) && AppInstance::find($custom_values['app_instance_id']))) {
            $parent_app_instance = $parent_app_instance ?? $is_shared_app ? $plan->shared_app : null;
            $this->parent_app_instance = $parent_app_instance;
        }

        if ($configurations) {
            $this->addCustomValue(['configurations' => $configurations]);
        }

        $this->addCustomValue($custom_values);

        if ($parent_app_instance) {
            $prereqs->add_application_required($parent_app_instance);
        }

        $prereqs->add_subscription_active();
        $this->prerequisites = $prereqs->get();

        if ($is_shared_app) {
            $this->status = TaskStatus::IN_PROGRESS;
            $this->addCustomValue(['shared_app' => true]);
        }
    }

    // Each request is its own activation, as it always was (the instance each made
    // used to keep two identical requests apart)
    public function task_exists()
    {
        return false;
    }

    /**
     * Once the task exists, and again when it is retried. A new activation makes
     * its app instance here.
     */
    public function onCreate(Task $task)
    {
        if (! $task->app_instance_id) {
            $this->createInstance($task);
        }

        // What it was created with also applies when it is retried
        $task->status = $this->status;

        if ($task->app_instance->usesOwnResources()) {
            // Add ldap groups
            AddLdapGroups::dispatch($task->app_instance);
        }
    }

    private function createInstance(Task $task): void
    {
        $application = Application::activate(
            organization: $this->organization,
            application: $this->application,
            version: $this->version,
            plan: $this->plan,
            parent_app: $this->parent_app_instance,
            label: $this->label,
            domain: $this->domain,
        );

        // Straight away, so that a failure further on can't lead a retry to make a second one
        $task->app_instance_id = $application->id;
        $task->custom_values = array_merge($task->customValues(), ['app_instance_id' => $application->id]);
        $task->save();

        foreach ($this->configurations ?? [] as $key => $value) {
            $application->updateSetting('configurations.'.$key, $value);
        }

        $application->features()->update(array_merge($this->customizations, ['app_instance_id' => $application->id]));

        if ($this->plan->sso_server && $application->usesOwnResources()) {
            if ($sso_task = ActionFacade::execute(new ApplicationSSOSetup($application->get()), background: true)) {
                $task->dependOn($sso_task);
                $task->custom_values = array_merge($task->customValues(), ['sso_task_id' => $sso_task->id]);
                $task->save();
            }
        }

        $application->refresh();

        ApplicationPreActivation::dispatch($application->app_instance);
    }

    public static function handle(Task $task): ActionResult
    {
        $plan = AppPlan::find($task->getValue('app_plan'));
        $app_instance = Application::instance($task->app_instance);
        $app_profile = Application::profile($app_instance->application->slug);
        $result = ActionResult::wait();

        // Add ldap groups
        AddLdapGroups::dispatch($task->app_instance);

        // Only parent app is responsible for activating app -- unless this
        // app is multisite (e.g. ERPNext), which keeps its own site/LDAP
        // identity even when shared.
        $uses_own_permissions = $app_instance->usesOwnResources();
        $organization_created = false;

        if ($uses_own_permissions && $plan->database_server && ! $app_instance->databasename) {
            // Add app database
            CreateApplicationDatabase::dispatch($task->app_instance, $task);
        }

        if ($uses_own_permissions && $app_profile->activationType($app_instance->get()) === 'chart') {
            $server = $app_instance->connect('web');
            if ($server->existsOrganization()) {
                $server->add();
            } else {
                $server->addOrganization();
                $organization_created = true;
            }
        } elseif ($app_profile->activationType($app_instance->get()) === 'job') {
            $new_task = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'activate'), $task, background: true);
            $parent_app_task = ActionFacade::execute(new ApplicationUpgrade($app_instance->parent, $app_instance->parent->version), $task, background: true);
            $result = $result->waitingFor(...array_filter([$new_task, $parent_app_task]));
        }

        // Create task to process customizations
        ActionFacade::execute(new ProcessCustomizations($task->app_instance, $task->customValues()), $task, delay: true);
        ApplicationActivating::dispatch($task->app_instance->refresh());

        // The organization has to exist on the server before the app can be added to it: run again
        if ($organization_created) {
            return ActionResult::retryLater(__('messages.action.organization_created'), 0);
        }

        return $result;
    }

    public static function retry(Task $task): ?Action
    {
        return new self($task->organization, $task->application, AppPlan::find($task->getValue('app_plan')), $task->custom_values, version: $task->version);
    }

    public static function poll(Task $task): ActionResult
    {
        OrganizationFacade::setOrganization($task->organization);
        $app_instance = Application::instance($task->app_instance);
        // A shared child normally defers entirely to its parent, but a
        // multisite app (e.g. ERPNext) keeps its own LDAP/site identity even
        // when shared, so it still needs default admin roles granted here --
        // connect('web')->isActive() already resolves to the parent's
        // chart/job status for a 'job' activation type (see
        // RancherWebInterface), so no further branching is needed below.
        $uses_own_permissions = $app_instance->usesOwnResources();
        $task_complete = false;
        if ($app_instance && $uses_own_permissions) {
            $server = $app_instance->connect('web');

            if (! $server->exists()) {
                return ActionResult::fail(__('messages.exception.app_not_found_on_server', [
                    'app' => $app_instance->application->name,
                    'guidance' => $server->notFoundMessage(),
                ]), TaskErrorCode::APP_NOT_FOUND);
            }

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
        } elseif (! $uses_own_permissions) {
            $task_complete = true;
        }

        if ($task_complete) {
            $app_instance->status = 'active';
            $app_instance->save();
            EventApplicationActivated::dispatch($app_instance->get());

            $subscription = (new SubscriptionService($task->organization))->all();
            ActionFacade::execute(new SubscriptionUpdate($task->organization, $subscription), background: true);

            return ActionResult::done()->then(function (Task $task) {
                $task->organization->notifyAdmins(new ApplicationActivated($task));
                $task->groupNotified();
            });
        }

        return ActionResult::wait();
    }
}
